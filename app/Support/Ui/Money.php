<?php

namespace App\Support\Ui;

use NumberFormatter;

/**
 * Shared currency/number formatter. The spec (00/10/20/30) requires every money
 * value to go through ONE formatter so the ₪ symbol placement follows the active
 * locale (Hebrew typically trails the number) — never glued with string concat.
 *
 * Numeric/currency strings stay LTR even inside an RTL row; the calling Blade
 * wraps them in .rc-ltr.
 */
final class Money
{
    // === CONSTANTS ===
    public const DEFAULT_CURRENCY = 'ILS';

    public static function format(float|int|string|null $amount, string $currency = self::DEFAULT_CURRENCY): string
    {
        $value = (float) ($amount ?? 0);
        $locale = app()->getLocale() === 'he' ? 'he_IL' : 'en_IL';

        if (class_exists(NumberFormatter::class)) {
            $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);

            return (string) $fmt->formatCurrency($value, $currency);
        }

        // Fallback if intl is unavailable: symbol + grouped number (still no glue logic per-locale).
        $symbol = $currency === 'ILS' ? '₪' : $currency . ' ';

        return $symbol . number_format($value, 2);
    }

    /** A bare grouped number (no currency symbol) for counts/ratios. */
    public static function number(float|int|null $value, int $decimals = 0): string
    {
        return number_format((float) ($value ?? 0), $decimals);
    }
}
