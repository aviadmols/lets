<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

use Carbon\CarbonInterface;

/**
 * Recurring billing cadence (ported from the reference engine's BillingFrequency).
 * `addTo()` advances next_charge_at by exactly one cycle — the single source of
 * truth for "when does this plan bill again". intervalCount lets a frequency
 * carry "every N" (e.g. every 2 weeks) via the plan's interval_count column.
 */
enum BillingFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case BIWEEKLY = 'biweekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';

    /**
     * Advance a base instant by one cycle (optionally repeated intervalCount).
     */
    public function addTo(CarbonInterface $base, int $intervalCount = 1): CarbonInterface
    {
        $n = max(1, $intervalCount);

        return match ($this) {
            self::DAILY => $base->copy()->addDays($n),
            self::WEEKLY => $base->copy()->addWeeks($n),
            self::BIWEEKLY => $base->copy()->addWeeks(2 * $n),
            self::MONTHLY => $base->copy()->addMonthsNoOverflow($n),
            self::QUARTERLY => $base->copy()->addMonthsNoOverflow(3 * $n),
            self::YEARLY => $base->copy()->addYearsNoOverflow($n),
        };
    }

    /**
     * Step a boundary BACK by one cycle — the inverse of addTo(), for callers
     * that hold the END of a period (next_charge_at) and need its start (the
     * proration denominator). A separate method rather than a signed count:
     * addTo() clamps its count to ≥1, so a negative there silently steps
     * FORWARD — the exact bug this method exists to make unwritable.
     */
    public function subFrom(CarbonInterface $base, int $intervalCount = 1): CarbonInterface
    {
        $n = max(1, $intervalCount);

        return match ($this) {
            self::DAILY => $base->copy()->subDays($n),
            self::WEEKLY => $base->copy()->subWeeks($n),
            self::BIWEEKLY => $base->copy()->subWeeks(2 * $n),
            self::MONTHLY => $base->copy()->subMonthsNoOverflow($n),
            self::QUARTERLY => $base->copy()->subMonthsNoOverflow(3 * $n),
            self::YEARLY => $base->copy()->subYearsNoOverflow($n),
        };
    }

    /** A stable cycle-date stamp for the idempotency key (recurring:{...}:{date}). */
    public function cycleStamp(CarbonInterface $when): string
    {
        return $when->format('Y-m-d');
    }
}
