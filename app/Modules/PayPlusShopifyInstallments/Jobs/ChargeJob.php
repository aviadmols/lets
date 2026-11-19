<?php

namespace App\Modules\PayPlusShopifyInstallments\Jobs;

use App\Domain\Billing\ShabbatHold;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Charge one plan, tenant-safely. Layer 1 of the four-layer idempotency wall:
 * ShouldBeUnique keyed by shop+plan+type collapses scheduler overlap / webhook
 * retry / double-click to a single in-flight job. The TenantContext middleware
 * binds the shop in handle() and ALWAYS clears it after — context never leaks to
 * the next job on the same long-lived worker.
 *
 * shop_id is carried EXPLICITLY (never inferred from global state).
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Jobs/ChargeInstallmentJob.php
 */
final class ChargeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_CHARGES;

    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 600;

    public int $tries = 1; // retries are domain-scheduled (backoff), not queue-level

    public function __construct(
        public readonly int $shopId,
        public readonly int $planId,
        public readonly string $paymentType,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** Layer 1: deterministic, shop-namespaced uniqueness. */
    public function uniqueId(): string
    {
        return sprintf('shop:%d:plan:%d:type:%s', $this->shopId, $this->planId, $this->paymentType);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(ChargeOrchestrator $orchestrator): void
    {
        // Tenant is already bound by TenantContext middleware and cleared after.
        if ($this->heldForShabbat()) {
            return;
        }

        $orchestrator->charge($this->planId, PaymentType::from($this->paymentType));
    }

    /**
     * The shop keeps Shabbat and this charge must wait (ShabbatHold).
     *
     * The scheduler does not queue a held charge in the first place; this catches
     * the rest. A job queued at 23:59 on Friday that a busy worker picks up after
     * midnight, the charge a card update queues, a token-recovery run.
     *
     * The charge is not simply DROPPED. A plan the scheduler will find still due
     * is collected by Sunday's first run; a stopped plan whose customer just
     * fixed their card is not, so a resume is queued for Sunday 00:01, and it
     * charges only if the plan is still exactly as it was held. Nothing is
     * written for the money: no payment
     * row, no ledger row, no attempt, so Sunday's charge starts clean. The plan's
     * Timeline says it was held, so a merchant who reads it knows why.
     */
    private function heldForShabbat(): bool
    {
        // FAILS OPEN, like the scheduler's read: a problem asking the question is
        // never allowed to kill the charge (tries = 1). Not knowing means
        // charging as every shop did before this setting existed.
        try {
            if (! MerchantBillingSettings::current()->observesShabbat()) {
                return false;
            }

            $plan = InstallmentPlan::query()->find($this->planId);
            if ($plan === null) {
                return false; // the orchestrator's own not-found path answers this
            }

            $now = now();
            if (! ShabbatHold::holds($plan->next_charge_at, $now)) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('charging.shabbat_check_failed', [
                'shop_id' => $this->shopId,
                'plan_id' => $this->planId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $resumeAt = ShabbatHold::liftsAt($plan->next_charge_at, $now)->addMinutes(ShabbatHold::RESUME_GRACE_MINUTES);
        $status = $plan->status instanceof PlanStatus ? $plan->status->value : (string) $plan->status;

        // The plan as it stands NOW travels with the resume, which charges on
        // Sunday only if nothing about it has changed (see that job).
        ResumeShabbatHeldChargeJob::dispatch(
            $this->shopId,
            $this->planId,
            $this->paymentType,
            $status,
            $plan->next_charge_at?->toIso8601String(),
        )->delay($resumeAt);

        Timeline::record(
            kind: Timeline::KIND_CHARGE_HELD_FOR_SHABBAT,
            details: ['type' => $this->paymentType, 'resume_at' => $resumeAt->toIso8601String()],
            planId: $this->planId,
            shopId: $this->shopId,
        );

        return true;
    }
}
