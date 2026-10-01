<?php

namespace App\Support\Ui\Charts;

use App\Support\Ui\Money;

/**
 * How a chart prints a number — axis ticks, tooltips, the hidden data table.
 * One switch so a "money" chart never prints a bare 61480 in its tooltip.
 */
final class ChartFormat
{
    // === CONSTANTS ===
    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    public const FORMATS = [self::NUMBER, self::MONEY, self::PERCENT];

    public static function value(float|int|null $value, string $format = self::NUMBER): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            self::MONEY => self::money((float) $value),
            self::PERCENT => Money::number((float) $value, abs((float) $value - round((float) $value)) > 0.001 ? 1 : 0).'%',
            default => Money::number((float) $value, abs((float) $value - round((float) $value)) > 0.001 ? 1 : 0),
        };
    }

    /** ₪12,345 — whole shekels at and above ₪100, agorot below (the brief's rule). */
    public static function money(float $value): string
    {
        $formatted = Money::format($value);
        if (abs($value) >= 100) {
            $formatted = (string) preg_replace('/[.,]00(?=\D*$)/u', '', $formatted);
        }

        return $formatted;
    }

    /** Signed count for movement panels: +43 / −12 / 0. */
    public static function signed(float|int $value, string $format = self::NUMBER): string
    {
        $abs = self::value(abs((float) $value), $format);

        return match (true) {
            $value > 0 => '+'.$abs,
            $value < 0 => '−'.$abs,
            default => $abs,
        };
    }
}
