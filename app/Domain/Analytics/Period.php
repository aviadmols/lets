<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;

/**
 * The window an Analytics screen reads, and the window it is compared with.
 *
 * Immutable. Built once per request from the URL (range preset + optional
 * custom dates + compare mode) and handed to every query class, so a whole
 * screen reads ONE definition of "the period" — and the cache key that comes
 * with it.
 *
 * Every bound is a whole day: start() is 00:00:00, end() is 23:59:59 of the
 * last day. "Last 30 days" INCLUDES today (today and the 29 days before it),
 * which is what a merchant reading "30 days" expects to see counted.
 */
final class Period
{
    // === CONSTANTS ===
    public const RANGE_7 = '7d';

    public const RANGE_30 = '30d';

    public const RANGE_90 = '90d';

    public const RANGE_MTD = 'mtd';

    public const RANGE_YTD = 'ytd';

    public const RANGE_CUSTOM = 'custom';

    /** Presets in menu order; the value is the day count for the rolling ones. */
    public const RANGES = [
        self::RANGE_7 => 7,
        self::RANGE_30 => 30,
        self::RANGE_90 => 90,
        self::RANGE_MTD => null,
        self::RANGE_YTD => null,
        self::RANGE_CUSTOM => null,
    ];

    public const DEFAULT_RANGE = self::RANGE_30;

    public const COMPARE_PREVIOUS_PERIOD = 'previous_period';

    public const COMPARE_PREVIOUS_YEAR = 'previous_year';

    public const COMPARE_NONE = 'none';

    public const COMPARES = [
        self::COMPARE_PREVIOUS_PERIOD,
        self::COMPARE_PREVIOUS_YEAR,
        self::COMPARE_NONE,
    ];

    public const DEFAULT_COMPARE = self::COMPARE_PREVIOUS_PERIOD;

    /** A custom range longer than this is clipped — a screen is not a data warehouse. */
    public const MAX_CUSTOM_DAYS = 1100;

    /** The URL/date-input format. */
    public const DATE_FORMAT = 'Y-m-d';

    private function __construct(
        public readonly string $range,
        public readonly string $compare,
        private readonly CarbonImmutable $start,
        private readonly CarbonImmutable $end,
    ) {}

    /**
     * Build from URL values. Anything unknown or malformed falls back to the
     * default rather than throwing — a hand-edited link still opens a screen.
     */
    public static function fromInput(
        ?string $range,
        ?string $compare = null,
        ?string $from = null,
        ?string $to = null,
        ?CarbonImmutable $today = null,
    ): self {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $range = array_key_exists((string) $range, self::RANGES) ? (string) $range : self::DEFAULT_RANGE;
        $compare = in_array($compare, self::COMPARES, true) ? (string) $compare : self::DEFAULT_COMPARE;

        [$start, $end] = match ($range) {
            self::RANGE_MTD => [$today->startOfMonth(), $today],
            self::RANGE_YTD => [$today->startOfYear(), $today],
            self::RANGE_CUSTOM => self::customBounds($from, $to, $today),
            default => [$today->subDays(self::RANGES[$range] - 1), $today],
        };

        if ($start === null) { // a custom range we could not read
            $range = self::DEFAULT_RANGE;
            [$start, $end] = [$today->subDays(self::RANGES[$range] - 1), $today];
        }

        return new self($range, $compare, $start->startOfDay(), $end->endOfDay());
    }

    public static function default(?CarbonImmutable $today = null): self
    {
        return self::fromInput(self::DEFAULT_RANGE, self::DEFAULT_COMPARE, null, null, $today);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private static function customBounds(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $start = self::parseDate($from);
        $end = self::parseDate($to) ?? $today;
        if ($start === null) {
            return [null, null];
        }
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($end->greaterThan($today)) {
            $end = $today;
        }
        if ($start->greaterThan($end)) {
            $start = $end;
        }
        if ($start->diffInDays($end) + 1 > self::MAX_CUSTOM_DAYS) {
            $start = $end->subDays(self::MAX_CUSTOM_DAYS - 1);
        }

        return [$start, $end];
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(self::DATE_FORMAT, $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    // === The window ===

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    /** Whole days in the window (both ends inclusive). */
    public function days(): int
    {
        return (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }

    public function contains(CarbonImmutable $moment): bool
    {
        return $moment->betweenIncluded($this->start, $this->end);
    }

    // === The comparison window ===

    public function hasCompare(): bool
    {
        return $this->compare !== self::COMPARE_NONE;
    }

    /** The comparison window as its own Period (no compare of its own), or null. */
    public function comparison(): ?self
    {
        if (! $this->hasCompare()) {
            return null;
        }

        if ($this->compare === self::COMPARE_PREVIOUS_YEAR) {
            return new self(self::RANGE_CUSTOM, self::COMPARE_NONE, $this->start->subYear()->startOfDay(), $this->end->subYear()->endOfDay());
        }

        $end = $this->start->subDay()->endOfDay();

        return new self(self::RANGE_CUSTOM, self::COMPARE_NONE, $end->subDays($this->days() - 1)->startOfDay(), $end);
    }

    /** The earliest moment any query for this screen needs (window or comparison). */
    public function earliest(): CarbonImmutable
    {
        return $this->comparison()?->start()->min($this->start) ?? $this->start;
    }

    // === Identity ===

    /** Stable cache/morph key: same window + same compare = same key. */
    public function key(): string
    {
        return $this->start->format(self::DATE_FORMAT).'_'.$this->end->format(self::DATE_FORMAT).'_'.$this->compare;
    }

    /** "31 Aug – 29 Sep 2026" in the active locale. */
    public function label(): string
    {
        return self::spanLabel($this->start, $this->end);
    }

    public static function spanLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $locale = app()->getLocale();
        $s = $start->locale($locale);
        $e = $end->locale($locale);

        if ($s->isSameDay($e)) {
            return $e->translatedFormat('j M Y');
        }
        if ($s->year !== $e->year) {
            return $s->translatedFormat('j M Y').' – '.$e->translatedFormat('j M Y');
        }
        if ($s->month === $e->month) {
            return $s->translatedFormat('j').' – '.$e->translatedFormat('j M Y');
        }

        return $s->translatedFormat('j M').' – '.$e->translatedFormat('j M Y');
    }
}
