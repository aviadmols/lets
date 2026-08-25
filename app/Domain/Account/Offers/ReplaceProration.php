<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use Illuminate\Support\Carbon;

/**
 * The money of a PRORATED subscription switch — one formula, one home.
 *
 * dueNow = round(max(0, new − old) × days_remaining / days_in_cycle, 2)
 *
 *   new             the target's per-cycle price (the quote's amount)
 *   old             what the shopper pays per cycle today (installment_amount)
 *   days_in_cycle   old renewal date minus one billing step → old renewal date
 *   days_remaining  today → old renewal date
 *
 * TWO CALLERS, ONE ANSWER. The presenter computes it to write the disclosure the
 * shopper reads; the accept service computes it again to decide the charge. They
 * must call THIS class — a re-implementation that drifted by a rounding rule
 * would charge a different number than the card promised.
 *
 * TIME ONLY SHRINKS IT. Between the page load and the click, days_remaining can
 * only fall, so the charge can only be LESS than the sentence said — which is
 * why the accept path re-derives instead of trusting the shown figure, and why
 * no epsilon guard is needed on it.
 *
 * A DOWNGRADE IS ZERO, not a refund: the shopper keeps the period they paid for
 * at the price they paid, and the lower price starts at the next cycle. Zero —
 * or a source with no renewal date to prorate against — means "charge nothing
 * now", which the accept path treats exactly like a period-end switch.
 */
final class ReplaceProration
{
    // === CONSTANTS ===
    /** Below the gateway's floor there is nothing chargeable — treat as zero. */
    public const MIN_CHARGE = 0.01;

    /**
     * The amount to charge NOW for this switch, or null when the target does not
     * prorate at all (any other timing). 0.0 is a real answer: "prorated, and
     * nothing to charge".
     */
    public static function dueNow(AccountOfferTarget $target, ?InstallmentPlan $source, float $newCyclePrice): ?float
    {
        if ($target->timing() !== AccountOfferTarget::TIMING_PRORATED) {
            return null;
        }

        if ($source === null) {
            return 0.0;
        }

        $renewal = $source->next_charge_at instanceof Carbon
            ? $source->next_charge_at->copy()->startOfDay()
            : null;

        $today = now()->startOfDay();

        // Nothing left of the period (or no schedule at all) → nothing to prorate.
        if ($renewal === null || ! $renewal->greaterThan($today)) {
            return 0.0;
        }

        $frequency = $source->billing_frequency;
        if ($frequency === null) {
            return 0.0;
        }

        $cycleStart = Carbon::parse(
            $frequency->subFrom($renewal, max(1, (int) $source->interval_count)),
        )->startOfDay();

        $daysInCycle = max(1, $cycleStart->diffInDays($renewal));
        $daysRemaining = min($daysInCycle, max(0, $today->diffInDays($renewal)));

        $difference = max(0.0, $newCyclePrice - round((float) $source->installment_amount, 2));

        $due = round($difference * $daysRemaining / $daysInCycle, 2);

        return $due >= self::MIN_CHARGE ? $due : 0.0;
    }
}
