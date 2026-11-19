<?php

namespace App\Modules\PayPlusShopifyInstallments\Jobs;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Puts a charge that Shabbat held back on the queue on Sunday, IF it is still
 * the charge that was held.
 *
 * WHY IT EXISTS. The scheduler finds an ordinary held plan still due on Sunday
 * by itself. It does NOT find a STOPPED plan (failed, outside
 * PlanStatus::chargeable()) whose customer fixed their card on the Saturday;
 * the charge the card update queued is the only thing that would ever ask for
 * that money. This job is that ask, a day later.
 *
 * WHY IT RE-CHECKS. The ChargeOrchestrator has no status or retry-backoff gate;
 * it relies on whoever queued the charge to have filtered. That filtering ran
 * on Saturday. So the plan is re-read now and the charge goes ahead only when
 * nothing has changed since the hold:
 *   - same status, and the same next charge date, as when it was held: a plan
 *     that was cancelled, paused, rescheduled or paid in the meantime is not
 *     charged on the strength of a Saturday decision;
 *   - and NOT a chargeable status: the scheduler collects those itself, with its
 *     retry backoff and once-a-day filters. Asking here as well would re-ask a
 *     card a minute after the scheduler's own attempt was declined.
 *
 * Its own job, not a delayed ChargeJob: the held ChargeJob still holds its
 * ShouldBeUnique lock while it runs, so a ChargeJob dispatched from inside it
 * would be dropped by that lock. Unique per plan for the length of a hold.
 *
 * shop_id is carried EXPLICITLY; TenantContext binds and always clears it.
 */
final class ResumeShabbatHeldChargeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_CHARGES;

    /** Longer than any hold (a Saturday, plus Friday night's early window). */
    public int $uniqueFor = 3 * 24 * 3600;

    public int $tries = 3;

    /**
     * @param  string  $heldStatus  the plan's status when it was held
     * @param  string|null  $heldNextChargeAt  its next_charge_at then (ISO-8601), null when it had none
     */
    public function __construct(
        public readonly int $shopId,
        public readonly int $planId,
        public readonly string $paymentType,
        public readonly string $heldStatus,
        public readonly ?string $heldNextChargeAt,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shabbat-resume:shop:%d:plan:%d:type:%s', $this->shopId, $this->planId, $this->paymentType);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(): void
    {
        // Tenant-scoped lookup (TenantContext bound the shop).
        $plan = InstallmentPlan::query()->find($this->planId);

        $why = $this->reasonNotToCharge($plan);
        if ($why !== null) {
            Log::info('charging.shabbat_resume_skipped', [
                'shop_id' => $this->shopId,
                'plan_id' => $this->planId,
                'reason' => $why,
            ]);

            return;
        }

        ChargeJob::dispatch($this->shopId, $this->planId, $this->paymentType);
    }

    /** Null when the held charge should go ahead; otherwise the reason it should not. */
    private function reasonNotToCharge(?InstallmentPlan $plan): ?string
    {
        if ($plan === null) {
            return 'plan_gone';
        }

        $status = $plan->status instanceof PlanStatus ? $plan->status->value : (string) $plan->status;

        if ($status !== $this->heldStatus) {
            return 'status_changed';
        }

        if ($plan->next_charge_at?->toIso8601String() !== $this->heldNextChargeAt) {
            return 'date_changed';
        }

        $chargeable = array_map(static fn ($s): string => $s instanceof PlanStatus ? $s->value : (string) $s, PlanStatus::chargeable());

        // A chargeable plan the scheduler will find due is left to it. One it
        // will NOT find yet (a failed slot still owed, next date in the future)
        // is what the card update was asking for; that ask goes ahead.
        $owesWhileNotDue = $plan->payment_failed_at !== null
            && $plan->next_charge_at !== null
            && $plan->next_charge_at->isFuture();

        if (in_array($status, $chargeable, true) && ! $owesWhileNotDue) {
            return 'scheduler_collects';
        }

        return null;
    }
}
