<?php

namespace App\Domain\Billing;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;

/**
 * THE single source of truth for a recurring plan's per-cycle amount — used by
 * ChargeOrchestrator (the money that moves), BOTH platform order strategies
 * (the order that mirrors it) and ViewSubscription (the number the merchant
 * reads). One resolver so those three can never diverge.
 *
 * Charge ordinals count the CHECKOUT as charge #1. Mechanically: the checkout
 * payment is recorded as a SUCCEEDED slot at sequence 0 (PlanActivationService::
 * recordDepositPaymentSlot) and nextSequenceFor(recurring) = count(succeeded)+1,
 * so the sequence a recurring slot is born with EQUALS its charge ordinal
 * (first recurring cycle = seq 2 = charge #2; seq 1 is never used on recurring).
 *
 * The intro-discount window (snapshot column discount_cycles, copied from the
 * template at creation) keeps installment_amount while chargeNumber <= window
 * and steps up to regular_amount after. Legacy plans carry null snapshots and
 * fall through to installment_amount — behavior unchanged.
 */
final class CycleAmountResolver
{
    // === CONSTANTS ===
    /** Ladder rungs, for logging/tests. */
    public const SOURCE_OVERRIDE = 'next_order_override';

    public const SOURCE_FIRST_CHARGE = 'offer_first_charge';

    public const SOURCE_REGULAR = 'regular_amount';

    public const SOURCE_STEADY = 'installment_amount';

    /**
     * The ordinal (checkout = #1) of the NEXT charge — same derivation as the
     * orchestrator's nextSequenceFor() for recurring plans.
     */
    public function chargeNumberForNext(InstallmentPlan $plan): int
    {
        return $this->succeededCount($plan) + 1;
    }

    /**
     * The amount to charge for charge #$chargeNumber. Ladder:
     *   1. the one-time next-order override (W25) — still wins over everything;
     *   2. a PRORATED switch's one-off first charge (charge #1 only): the plan
     *      was born from an offer whose deal is "the difference now, the full
     *      price from the old renewal" — installment_amount stays that full
     *      price, and this rung is what makes the first slot the difference;
     *   3. past the intro window → regular_amount (the stepped-up price);
     *   4. the steady-state installment_amount.
     */
    public function amountForCharge(InstallmentPlan $plan, int $chargeNumber): float
    {
        $override = $plan->nextOrderOverride();
        if ($override !== null && isset($override['amount'])) {
            return round((float) $override['amount'], 2);
        }

        $firstCharge = $plan->offerFirstChargeAmount();
        if ($firstCharge !== null && $chargeNumber <= 1) {
            return $firstCharge;
        }

        if ($this->windowEndedAt($plan, $chargeNumber)) {
            return round((float) $plan->regular_amount, 2);
        }

        return round((float) $plan->installment_amount, 2);
    }

    /**
     * The amount a platform CYCLE ORDER should carry, called after a charge
     * succeeded: prefer the money that ACTUALLY moved (the latest succeeded
     * recurring slot's frozen amount) so the order can never disagree with the
     * ledger; fall back to the ladder for the cycle that just completed.
     */
    public function amountForCycleOrder(InstallmentPlan $plan): float
    {
        $latest = $plan->payments()
            ->where('status', PaymentStatus::SUCCEEDED->value)
            ->where('sequence', '>', 0)
            ->orderByDesc('sequence')
            ->first();

        if ($latest instanceof InstallmentPayment && (float) $latest->amount > 0) {
            return round((float) $latest->amount, 2);
        }

        return $this->amountForCharge($plan, max(1, $this->succeededCount($plan)));
    }

    /**
     * The discount-tag predicate: is charge #$chargeNumber priced BELOW the
     * plan's regular (undiscounted) amount? Covers both the intro window and
     * keep-first-payment-below-catalog. Null regular_amount (legacy plan, or a
     * plan born without a template) can never claim a discount.
     */
    public function isDiscountedCycle(InstallmentPlan $plan, int $chargeNumber): bool
    {
        if ($plan->plan_kind !== PlanKind::RECURRING || $plan->regular_amount === null) {
            return false;
        }

        return $this->amountForCharge($plan, $chargeNumber) < round((float) $plan->regular_amount, 2);
    }

    /**
     * Progress through the intro window for display: ['used' => n, 'total' => N],
     * or null when the plan has no window. `used` counts succeeded charges
     * (checkout included) capped at the window size.
     *
     * @return array{used: int, total: int}|null
     */
    public function introWindowStatus(InstallmentPlan $plan): ?array
    {
        $window = (int) ($plan->discount_cycles ?? 0);
        if ($plan->plan_kind !== PlanKind::RECURRING || $window <= 0) {
            return null;
        }

        return [
            'used' => min($this->succeededCount($plan), $window),
            'total' => $window,
        ];
    }

    /** True when the intro window exists, has passed, and a step-up target is known. */
    public function windowEndedAt(InstallmentPlan $plan, int $chargeNumber): bool
    {
        $window = (int) ($plan->discount_cycles ?? 0);

        return $window > 0
            && $chargeNumber > $window
            && (float) ($plan->regular_amount ?? 0) > 0;
    }

    private function succeededCount(InstallmentPlan $plan): int
    {
        return $plan->payments()->where('status', PaymentStatus::SUCCEEDED->value)->count();
    }
}
