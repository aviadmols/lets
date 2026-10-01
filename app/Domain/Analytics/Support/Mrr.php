<?php

namespace App\Domain\Analytics\Support;

/**
 * Monthly Recurring Revenue normalisation — ONE definition for the whole
 * module (Sql::planMrr / Sql::contractMrr build the SQL from these tables).
 *
 * The rule we chose (docs/analytics/data-map.md §Definitions):
 *   - month-based cadences divide exactly: monthly ×1, quarterly ÷3, yearly ÷12,
 *     "every N months" ÷N — so a ₪100 monthly plan is ₪100 of MRR, not ₪98.6;
 *   - day-based cadences use the average Gregorian month (30.44 days):
 *     weekly ×30.44/7, every-2-weeks ×30.44/14, daily ×30.44;
 *   - interval_count divides the factor (every 2 months = ÷2);
 *   - a comped (no_charge) subscription is a subscriber but ₪0 of MRR — its
 *     amount is what the membership is worth, not money anybody is billed.
 */
final class Mrr
{
    // === CONSTANTS ===
    /** Average Gregorian month, in days. */
    public const MONTH_DAYS = 30.44;

    /** Cycles-per-month factor per PayPlus billing_frequency (before interval_count). */
    public const PLAN_MONTHLY_FACTOR = [
        'daily' => self::MONTH_DAYS,
        'weekly' => self::MONTH_DAYS / 7,
        'biweekly' => self::MONTH_DAYS / 14,
        'monthly' => 1.0,
        'quarterly' => 1 / 3,
        'yearly' => 1 / 12,
    ];

    /** Cycles-per-month factor per Shopify billing interval (before interval_count). */
    public const CONTRACT_MONTHLY_FACTOR = [
        'DAY' => self::MONTH_DAYS,
        'WEEK' => self::MONTH_DAYS / 7,
        'MONTH' => 1.0,
        'YEAR' => 1 / 12,
    ];

    /** PHP twin of Sql::planMrr — for fixtures, tests and single rows. */
    public static function forPlan(float $amount, ?string $frequency, int $intervalCount = 1, bool $noCharge = false): float
    {
        if ($noCharge) {
            return 0.0;
        }

        $factor = self::PLAN_MONTHLY_FACTOR[(string) $frequency] ?? 1.0;

        return $amount * $factor / max(1, $intervalCount);
    }

    /** PHP twin of Sql::contractMrr. */
    public static function forContract(float $amount, ?string $interval, int $intervalCount = 1): float
    {
        $factor = self::CONTRACT_MONTHLY_FACTOR[strtoupper((string) $interval)] ?? 1.0;

        return $amount * $factor / max(1, $intervalCount);
    }
}
