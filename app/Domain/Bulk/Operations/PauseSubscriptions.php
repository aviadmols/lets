<?php

namespace App\Domain\Bulk\Operations;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;

/**
 * Put a whole group of subscriptions on hold.
 *
 * The scheduler's due-query only bills chargeable statuses, so a paused plan
 * simply stops being asked for money — nothing is cancelled, nothing is refunded,
 * and "Resume" puts every one of them back. That reversibility is why pause is in
 * this screen at all while CANCEL is not: a mistaken bulk pause is one more bulk
 * edit to undo, and a mistaken bulk cancellation is four thousand apology emails
 * and a lost book of business.
 *
 * LEGAL SOURCES. Active, and awaiting_payment — the dunning state. Pausing a plan
 * that owes a cycle is the right verb for "stop chasing this group for now", and
 * the state machine already allows it. `next_charge_at` is left exactly where it
 * is: the date it owes is the one thing the hold exists to keep, and
 * SubscriptionLifecycleService::resume() reads it back.
 */
final class PauseSubscriptions extends LifecycleEdit
{
    // === CONSTANTS ===
    public const KEY = 'pause';

    public function key(): string
    {
        return self::KEY;
    }

    protected function sourceStatuses(): array
    {
        return [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_PAYMENT->value];
    }

    protected function targetStatus(): PlanStatus
    {
        return PlanStatus::PAUSED;
    }

    protected function move(InstallmentPlan $plan, string $reason): void
    {
        $this->lifecycle->pause($plan, $reason);
    }

    public function describe(array $params): string
    {
        return __('subscriptions.bulk.op.pause.summary');
    }
}
