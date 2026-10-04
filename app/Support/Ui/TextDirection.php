<?php

namespace App\Support\Ui;

/**
 * Which way the admin reads in the active locale. Server-side geometry (charts)
 * asks this instead of mirroring with CSS, so text and arrows inside a chart are
 * never flipped — only positions are. Filament's <html dir> comes from the same
 * locale (filament-panels::layout.direction), so the two cannot disagree.
 */
final class TextDirection
{
    // === CONSTANTS ===
    public const RTL = 'rtl';

    public const LTR = 'ltr';

    /** Locales whose script runs right-to-left (language subtag only). */
    public const RTL_LOCALES = ['he', 'ar', 'fa', 'ur'];

    public static function isRtl(?string $locale = null): bool
    {
        $language = strtolower(substr(str_replace('_', '-', $locale ?? app()->getLocale()), 0, 2));

        return in_array($language, self::RTL_LOCALES, true);
    }

    public static function of(?string $locale = null): string
    {
        return self::isRtl($locale) ? self::RTL : self::LTR;
    }
}
