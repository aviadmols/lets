<?php

namespace App\Domain\Billing;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * ONE CHARGE PER SUBSCRIPTION PER DAY — unless a person approved another.
 *
 * Why this exists, in production numbers. The cycle's idempotency key is built
 * from the plan's next charge DATE, and a successful charge moves that date a
 * cycle forward. So a second request arriving right after the first carried a
 * different key, nothing recognised it as the same money, and it charged NEXT
 * month on the spot:
 *
 *   14/09 18:05 → 18:08  a scheduler run queued a second job for 15 plans while
 *                        the first was still in the queue: charged twice each;
 *   10/09 09:07          two "charge now" presses, 8 seconds apart: twice;
 *   15/09 08:25          a card attach charged automatically, and "charge now"
 *                        4 seconds later charged again.
 *
 * The key is still the right key for "this cycle". What was missing is a wall
 * that does not depend on the key at all: a subscription that already moved
 * money in the last REPEAT_WINDOW_HOURS is not charged again by any automatic
 * path, and by a person only when they say so explicitly.
 *
 * WHAT COUNTS as money that moved: a cycle or installment slot with a
 * `charged_at` in the window — whatever its status since, because a charge that
 * was later refunded still happened today — and a `pending` ledger row opened in
 * the window, whose charge may be reaching PayPlus right now. A declined attempt
 * does not count: asking again after a refusal is how a card gets fixed.
 *
 * Deposits and upsells are not cycles of the plan and do not count.
 */
final class RepeatChargeGuard
{
    // === CONSTANTS ===
    /**
     * A rolling day, not the calendar day. "Once today" measured from midnight
     * would let 23:58 and 00:02 through as two different days — the same hole,
     * four minutes wide.
     */
    public const REPEAT_WINDOW_HOURS = 24;

    /** The slot types that are the plan's own charges. */
    public const CYCLE_TYPES = [PaymentType::RECURRING->value, PaymentType::INSTALLMENT->value];

    /**
     * The money this plan moved inside the window, newest first, or null.
     *
     * @return array{at: CarbonInterface, amount: float, in_flight: bool}|null
     */
    public function recentChargeOf(InstallmentPlan $plan): ?array
    {
        $since = now()->subHours(self::REPEAT_WINDOW_HOURS);

        $charged = InstallmentPayment::query()
            ->where('plan_id', $plan->getKey())
            ->whereIn('payment_type', self::CYCLE_TYPES)
            ->whereNotNull('charged_at')
            ->where('charged_at', '>=', $since)
            ->latest('charged_at')
            ->first();

        if ($charged !== null) {
            return ['at' => $charged->charged_at, 'amount' => (float) $charged->amount, 'in_flight' => false];
        }

        $pending = PaymentLedger::query()
            ->where('plan_id', $plan->getKey())
            ->where('status', PaymentLedger::STATUS_PENDING)
            ->whereIn('charge_context', self::CYCLE_TYPES)
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->first();

        return $pending === null
            ? null
            : ['at' => $pending->created_at, 'amount' => (float) $pending->amount, 'in_flight' => true];
    }

    /**
     * The same rule as a constraint on a PAYMENTS relation query, for the
     * scheduler's cross-tenant scan — so it never queues a job the orchestrator
     * would only refuse.
     */
    public static function chargedWithinWindow(Builder $payments): Builder
    {
        return $payments
            ->whereIn('payment_type', self::CYCLE_TYPES)
            ->whereNotNull('charged_at')
            ->where('charged_at', '>=', now()->subHours(self::REPEAT_WINDOW_HOURS));
    }
}
