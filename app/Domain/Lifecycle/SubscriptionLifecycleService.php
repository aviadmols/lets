<?php

namespace App\Domain\Lifecycle;

use App\Mail\PlanCancelledMail;
use App\Mail\Support\MailSettingsConfigurator;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Subscription lifecycle (the non-money-moving operations): PAUSE, RESUME, CANCEL.
 *
 * Every operation re-reads the plan under a row lock inside a transaction and uses
 * the GUARDED state machine (InstallmentPlan::transitionTo) — which is the ONLY legal
 * mutation path for `status` and which writes the Timeline (activity_events) audit row
 * itself. An illegal move (e.g. cancelling a completed plan) throws
 * IllegalTransitionException; the caller surfaces it.
 *
 * Tenant-safe: the plan is BelongsToShop-scoped, so the lookup only ever resolves the
 * bound shop's plan. Money law: NOTHING here charges or refunds — pause/resume only
 * move state + the clock; cancel additionally stops the scheduler and emails the
 * customer (best-effort). Refunds + out-of-schedule charges live in their own services.
 */
final class SubscriptionLifecycleService
{
    /** active → paused. The scheduler's due-query excludes paused plans, so no charge fires. */
    public function pause(InstallmentPlan $plan, ?string $reason = null): InstallmentPlan
    {
        return DB::transaction(function () use ($plan, $reason): InstallmentPlan {
            $fresh = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $fresh->transitionTo(PlanStatus::PAUSED, $this->context('paused', $reason));

            return $fresh;
        });
    }

    /**
     * paused → active. If the next charge date elapsed while paused, snap it to today
     * so the scheduler resumes promptly with ONE charge — never a backlog of every
     * cycle that was missed during the pause.
     */
    public function resume(InstallmentPlan $plan, ?string $reason = null): InstallmentPlan
    {
        return DB::transaction(function () use ($plan, $reason): InstallmentPlan {
            $fresh = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if ($fresh->next_charge_at !== null && $fresh->next_charge_at->isPast()) {
                $fresh->forceFill(['next_charge_at' => now()->startOfDay()])->save();
            }

            $fresh->transitionTo(PlanStatus::ACTIVE, $this->context('resumed', $reason));

            return $fresh;
        });
    }

    /**
     * active|paused|awaiting_first_payment|failed → cancelled. Stops the clock
     * (next_charge_at = null, defence in depth on top of the status filter) and emails
     * the customer the cancellation notice. Does NOT refund — a refund is a separate,
     * explicit money-out action.
     */
    public function cancel(InstallmentPlan $plan, ?string $reason = null): InstallmentPlan
    {
        $cancelled = DB::transaction(function () use ($plan, $reason): InstallmentPlan {
            $fresh = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $fresh->transitionTo(PlanStatus::CANCELLED, $this->context('cancelled', $reason));

            if ($fresh->next_charge_at !== null) {
                $fresh->forceFill(['next_charge_at' => null])->save();
            }

            return $fresh;
        });

        $this->sendCancellationEmail($cancelled, $reason);

        return $cancelled;
    }

    /** Best-effort customer email — a mail failure never undoes the cancellation. */
    private function sendCancellationEmail(InstallmentPlan $plan, ?string $reason): void
    {
        $to = (string) ($plan->customer_email ?? '');
        if ($to === '') {
            return;
        }

        try {
            MailSettingsConfigurator::apply($plan->shop); // per-shop SMTP (no-op when off)
            Mail::to($to)->send(new PlanCancelledMail(
                shop: $plan->shop,
                plan: $plan,
                cancellationReason: $reason,
            ));
        } catch (\Throwable $e) {
            Log::warning('lifecycle.cancellation_email_failed', [
                'plan_id' => $plan->getKey(),
                'shop_id' => $plan->shop_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> the Timeline detail bag for the transition. */
    private function context(string $action, ?string $reason): array
    {
        $context = ['action' => $action];
        if ($reason !== null && trim($reason) !== '') {
            $context['reason'] = trim($reason);
        }

        return $context;
    }
}
