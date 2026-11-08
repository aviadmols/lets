<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use Illuminate\Support\Carbon;

/**
 * When a subscription SWITCH may take money — the one answer the card, the accept
 * path and the eligibility wall all ask, so the sentence the shopper reads and the
 * charge that follows can never disagree.
 *
 * The merchant's configured timing is a ceiling, never an instruction to charge
 * twice for one period (11.2026: a shopper paid 79 for a month at noon, switched
 * to the 59 plan at 20:11, was charged 59 on the spot and lost the month she had
 * just paid for):
 *
 *   1. A DOWNGRADE never charges now. The shopper keeps the period they paid for,
 *      and the lower price starts on the old renewal date (period end).
 *   2. An IMMEDIATE switch over a period that is already PAID charges only the
 *      difference for the days left (prorated), and the full price resumes on the
 *      old renewal date. Across two cadences (monthly → yearly) there is no honest
 *      per-day difference, so it waits for the old renewal date instead.
 *   3. While the subscription is inside its commitment (min_cycles_before_exit —
 *      "cannot be cancelled before three months"), a downgrade is not on offer at
 *      all: lowering the price is leaving by another door.
 *
 * Everything else keeps the merchant's timing. An ADD, a one-time target, or the
 * merchant's preview (no source) answer exactly as before.
 */
final class SwitchTiming
{
    // === CONSTANTS ===
    /** Average days in ONE step of each cadence — to compare prices across cadences. */
    private const DAYS_PER_STEP = [
        BillingFrequency::DAILY->value => 1.0,
        BillingFrequency::WEEKLY->value => 7.0,
        BillingFrequency::BIWEEKLY->value => 14.0,
        BillingFrequency::MONTHLY->value => 30.4375,
        BillingFrequency::QUARTERLY->value => 91.3125,
        BillingFrequency::YEARLY->value => 365.25,
    ];

    /** Two per-day prices closer than this are the same price (rounding, not a downgrade). */
    private const PER_DAY_EPSILON = 0.0005;

    /**
     * The timing this switch actually runs with, or null when it replaces nothing
     * (an add, a one-time product).
     */
    public static function effective(AccountOfferTarget $target, ?InstallmentPlan $source, AccountOfferQuote $quote): ?string
    {
        $timing = $target->timing();

        if ($timing === null || $source === null || ! $target->isReplace()) {
            return $timing;
        }

        if (self::isDowngrade($source, $quote)) {
            return AccountOfferTarget::TIMING_PERIOD_END;
        }

        if ($timing === AccountOfferTarget::TIMING_IMMEDIATE && self::periodIsPaid($source)) {
            return self::sameCadence($source, $quote)
                ? AccountOfferTarget::TIMING_PRORATED
                : AccountOfferTarget::TIMING_PERIOD_END;
        }

        return $timing;
    }

    /** Does the effective timing take money today (before proration rounds it away)? */
    public static function chargesNow(AccountOfferTarget $target, ?InstallmentPlan $source, AccountOfferQuote $quote): bool
    {
        if (! $target->isSubscription() || ! $target->isReplace()) {
            return $target->chargesNow();
        }

        return self::effective($target, $source, $quote) !== AccountOfferTarget::TIMING_PERIOD_END;
    }

    /**
     * A replacement that lowers the price while the subscription is still inside
     * its commitment — never offered, never accepted.
     */
    public static function isLockedDowngrade(AccountOfferTarget $target, ?InstallmentPlan $source, AccountOfferQuote $quote): bool
    {
        return $source !== null
            && $target->isSubscription()
            && $target->isReplace()
            && ! $source->customerMayExit()
            && self::isDowngrade($source, $quote);
    }

    /** Is the new price lower than what the shopper pays today, per day of service? */
    public static function isDowngrade(InstallmentPlan $source, AccountOfferQuote $quote): bool
    {
        $old = self::perDay((float) $source->installment_amount, $source->billing_frequency, (int) $source->interval_count);
        $new = self::perDay((float) $quote->amount, $quote->frequency, (int) $quote->intervalCount);

        if ($old === null || $new === null) {
            // A cadence we cannot read: compare the per-cycle prices as they are.
            return round((float) $quote->amount, 2) < round((float) $source->installment_amount, 2);
        }

        return $new < $old - self::PER_DAY_EPSILON;
    }

    /**
     * Has the shopper already paid for the period they are in? Their renewal is
     * still ahead, and the last attempt did not fail. A subscription in dunning, or
     * one whose renewal date has passed, owes the period — a full charge now is
     * the payment, not a second one.
     */
    public static function periodIsPaid(InstallmentPlan $source): bool
    {
        $renewal = $source->next_charge_at instanceof Carbon ? $source->next_charge_at->copy()->startOfDay() : null;

        return $renewal !== null
            && $renewal->greaterThan(now()->startOfDay())
            && $source->payment_failed_at === null;
    }

    private static function sameCadence(InstallmentPlan $source, AccountOfferQuote $quote): bool
    {
        return $source->billing_frequency !== null
            && $quote->frequency !== null
            && $source->billing_frequency === $quote->frequency
            && max(1, (int) $source->interval_count) === max(1, (int) $quote->intervalCount);
    }

    private static function perDay(float $amount, ?BillingFrequency $frequency, int $intervalCount): ?float
    {
        $days = $frequency !== null ? (self::DAYS_PER_STEP[$frequency->value] ?? null) : null;

        return $days === null ? null : $amount / ($days * max(1, $intervalCount));
    }
}
