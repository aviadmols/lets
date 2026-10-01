<?php

namespace App\Domain\Analytics\Support;

/**
 * The change chip under every KPI: how much, which way, and whether that way
 * is GOOD. Direction and goodness are separate on purpose — churn going up is
 * an up-arrow in a red chip.
 *
 * Counts and money compare in PERCENT; rates compare in POINTS (3.2% → 3.6% is
 * "+0.4 pts", never "+12.5%").
 */
final class Delta
{
    // === CONSTANTS ===
    public const TONE_GOOD = 'good';

    public const TONE_BAD = 'bad';

    public const TONE_FLAT = 'flat';

    public const UNIT_PERCENT = 'percent';

    public const UNIT_POINTS = 'points';

    /** Changes smaller than this read as flat. */
    public const EPSILON = 0.05;

    /** Percent change, one decimal; null when there is no baseline to compare with. */
    public static function percent(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 1);
    }

    /** Point change between two rates (both already in percent), one decimal. */
    public static function points(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return round((float) $current - (float) $previous, 1);
    }

    public static function tone(?float $delta, bool $goodUp = true): string
    {
        if ($delta === null || abs($delta) < self::EPSILON) {
            return self::TONE_FLAT;
        }

        return ($delta > 0) === $goodUp ? self::TONE_GOOD : self::TONE_BAD;
    }

    /** A share in percent, one decimal; 0 when the whole is 0. */
    public static function share(float|int $part, float|int $whole): float
    {
        return (float) $whole === 0.0 ? 0.0 : round((float) $part / (float) $whole * 100, 1);
    }
}
