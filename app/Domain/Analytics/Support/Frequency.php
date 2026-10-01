<?php

namespace App\Domain\Analytics\Support;

/**
 * A delivery/billing cadence as ONE comparable key, whichever way it was
 * stored. "quarterly ×1" and "monthly ×3" are the same thing to a merchant
 * ("Every 3 months"), so both become 'm3'; "weekly ×2" and "biweekly ×1" are
 * both 'd14'.
 *
 *   m<N> — every N months (m1 monthly, m12 yearly)
 *   d<N> — every N days   (d1 daily, d7 weekly, d14 every 2 weeks)
 *
 * Sql::planFrequencyKey()/contractFrequencyKey() build the same key in SQL from
 * the unit tables below, so grouping and filtering never disagree.
 */
final class Frequency
{
    // === CONSTANTS ===
    public const UNIT_MONTH = 'm';

    public const UNIT_DAY = 'd';

    /** billing_frequency => [unit, multiplier] */
    public const PLAN_UNITS = [
        'daily' => [self::UNIT_DAY, 1],
        'weekly' => [self::UNIT_DAY, 7],
        'biweekly' => [self::UNIT_DAY, 14],
        'monthly' => [self::UNIT_MONTH, 1],
        'quarterly' => [self::UNIT_MONTH, 3],
        'yearly' => [self::UNIT_MONTH, 12],
    ];

    /** Shopify interval => [unit, multiplier] */
    public const CONTRACT_UNITS = [
        'DAY' => [self::UNIT_DAY, 1],
        'WEEK' => [self::UNIT_DAY, 7],
        'MONTH' => [self::UNIT_MONTH, 1],
        'YEAR' => [self::UNIT_MONTH, 12],
    ];

    public const KEY_PATTERN = '/^(m|d)(\d{1,4})$/';

    public static function planKey(?string $frequency, int $intervalCount = 1): string
    {
        [$unit, $multiplier] = self::PLAN_UNITS[(string) $frequency] ?? [self::UNIT_MONTH, 1];

        return $unit.(max(1, $intervalCount) * $multiplier);
    }

    public static function contractKey(?string $interval, int $intervalCount = 1): string
    {
        [$unit, $multiplier] = self::CONTRACT_UNITS[strtoupper((string) $interval)] ?? [self::UNIT_MONTH, 1];

        return $unit.(max(1, $intervalCount) * $multiplier);
    }

    public static function isValid(string $key): bool
    {
        return (bool) preg_match(self::KEY_PATTERN, $key);
    }

    /**
     * Every (billing_frequency, interval_count) pair that means $key — what a
     * frequency FILTER turns into on installment_plans.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function planPairs(string $key): array
    {
        return self::pairs($key, self::PLAN_UNITS);
    }

    /** @return list<array{0: string, 1: int}> */
    public static function contractPairs(string $key): array
    {
        return self::pairs($key, self::CONTRACT_UNITS);
    }

    /** @return list<array{0: string, 1: int}> */
    private static function pairs(string $key, array $units): array
    {
        if (! preg_match(self::KEY_PATTERN, $key, $m)) {
            return [];
        }
        $n = (int) $m[2];
        $out = [];
        foreach ($units as $name => [$unit, $multiplier]) {
            if ($unit === $m[1] && $n % $multiplier === 0) {
                $out[] = [(string) $name, intdiv($n, $multiplier)];
            }
        }

        return $out;
    }

    /** Sort weight: shorter cadences first, days before months of equal length. */
    public static function sortWeight(string $key): int
    {
        if (! preg_match(self::KEY_PATTERN, $key, $m)) {
            return PHP_INT_MAX;
        }

        return $m[1] === self::UNIT_DAY ? (int) $m[2] : (int) $m[2] * 31;
    }

    /** "Monthly", "Every 2 months", "Weekly", "Every 10 days" — translated. */
    public static function label(string $key): string
    {
        if (! preg_match(self::KEY_PATTERN, $key, $m)) {
            return $key;
        }
        $n = (int) $m[2];

        if ($m[1] === self::UNIT_MONTH) {
            return match (true) {
                $n === 1 => __('analytics.frequency.monthly'),
                $n === 12 => __('analytics.frequency.yearly'),
                $n % 12 === 0 => __('analytics.frequency.every_years', ['count' => intdiv($n, 12)]),
                default => __('analytics.frequency.every_months', ['count' => $n]),
            };
        }

        return match (true) {
            $n === 1 => __('analytics.frequency.daily'),
            $n === 7 => __('analytics.frequency.weekly'),
            $n % 7 === 0 => __('analytics.frequency.every_weeks', ['count' => intdiv($n, 7)]),
            default => __('analytics.frequency.every_days', ['count' => $n]),
        };
    }
}
