<?php

namespace App\Support\Ui\Charts;

/**
 * "Nice" axis steps (1, 2, 2.5, 5 × 10ⁿ) so tick labels read as 0 · 25 · 50,
 * never 0 · 23.7 · 47.4. Pure math, no rendering.
 */
final class NiceScale
{
    // === CONSTANTS ===
    /** The mantissas a step may take, ascending. */
    public const MANTISSAS = [1, 2, 2.5, 5, 10];

    /** The smallest nice step that is >= $raw (integer-only steps never go below 1). */
    public static function step(float $raw, bool $integer = false): float
    {
        if ($raw <= 0) {
            return 1.0;
        }

        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * 10;
        foreach (self::MANTISSAS as $mantissa) {
            if ($mantissa * $magnitude >= $raw - 1e-9) {
                $step = $mantissa * $magnitude;
                break;
            }
        }

        if ($integer) {
            $step = max(1.0, $step);
            if ($step < 10 && $step !== floor($step)) {
                $step = ceil($step); // 2.5 → 3 would not be nice; 2.5 only appears ≥ 25
            }
        }

        return (float) $step;
    }

    /**
     * A signed axis: $positive above zero, $negative (a magnitude) below, about
     * $intervals gridline gaps in all.
     *
     * @return array{step: float, above: int, below: int}
     */
    public static function signed(float $positive, float $negative, int $intervals = 4, bool $integer = true): array
    {
        $positive = max(0.0, $positive);
        $negative = max(0.0, $negative);

        if ($positive + $negative <= 0) {
            return ['step' => 1.0, 'above' => 1, 'below' => 0];
        }

        $step = self::step(($positive + $negative) / max(1, $intervals), $integer);
        $above = (int) ceil($positive / $step - 1e-9);
        $below = (int) ceil($negative / $step - 1e-9);

        if ($above + $below === 0) {
            $above = 1;
        }

        return ['step' => $step, 'above' => $above, 'below' => $below];
    }

    /**
     * A range axis with EXACTLY $intervals gaps that holds [$min, $max] — used
     * for a second (end) axis that must share the first axis's gridlines.
     *
     * @return array{low: float, step: float}
     */
    public static function fitted(float $min, float $max, int $intervals, bool $fromZero = false, bool $integer = false): array
    {
        $intervals = max(1, $intervals);
        if ($fromZero) {
            $min = min(0.0, $min);
        }
        if ($max - $min <= 0) {
            $pad = max(1.0, abs($max) * 0.1);
            $min = $fromZero ? $min : $min - $pad;
            $max += $pad;
        }

        $step = self::step(($max - $min) / $intervals, $integer);
        for ($guard = 0; $guard < 12; $guard++) {
            $low = $fromZero ? min(0.0, floor($min / $step) * $step) : floor($min / $step) * $step;
            if ($low + $intervals * $step >= $max - 1e-9) {
                return ['low' => (float) $low, 'step' => $step];
            }
            $step = self::step($step * 1.0001, $integer);
        }

        return ['low' => (float) floor($min), 'step' => (float) ceil(($max - $min) / $intervals)];
    }
}
