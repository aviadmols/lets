<?php

namespace App\Domain\Bulk\Operations;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;

/**
 * Take a whole group of subscriptions off hold.
 *
 * The undo of a bulk pause, and the second half of a seasonal shutdown: pause the
 * book in July, resume it in August. It goes through
 * SubscriptionLifecycleService::resume(), which carries two rules this operation
 * must not restate and must not lose:
 *
 *   - a plan whose date elapsed while it was paused is snapped to TODAY, so the
 *     scheduler bills it once rather than working through every cycle it missed;
 *   - unless WE paused it for an unpaid cycle (`payment_failed_at` is stamped), in
 *     which case it keeps the date it owes — resuming must not read as forgiving
 *     last month's money.
 *
 * Reusing the service is the whole design. A bulk resume that wrote
 * `status = active` itself would be the one path in the app where those two rules
 * do not apply, and nobody would find out until a thousand subscribers were
 * charged for a July that was cancelled.
 */
final class ResumeSubscriptions extends LifecycleEdit
{
    // === CONSTANTS ===
    public const KEY = 'resume';

    public function key(): string
    {
        return self::KEY;
    }

    protected function sourceStatuses(): array
    {
        return [PlanStatus::PAUSED->value];
    }

    protected function targetStatus(): PlanStatus
    {
        return PlanStatus::ACTIVE;
    }

    protected function move(InstallmentPlan $plan, string $reason): void
    {
        $this->lifecycle->resume($plan, $reason);
    }

    public function describe(array $params): string
    {
        return __('subscriptions.bulk.op.resume.summary');
    }
}
