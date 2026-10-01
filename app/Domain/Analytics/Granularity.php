<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;

/**
 * How a trend chart slices its Period into buckets: days, ISO weeks, calendar
 * months. Buckets are CLIPPED to the period — the first week of "last 30 days"
 * starts on the period's first day, not on the Monday before it — so a bucket
 * never counts a day the period does not.
 *
 * The SQL side groups by DAY only (Sql::day(), portable across SQLite and
 * Postgres) and rollUp() folds those day totals into whichever buckets the
 * chart asked for. One query shape serves every granularity.
 */
enum Granularity: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    // === CONSTANTS ===
    /** Above this many days a daily chart is a barcode — the toggle hides "Daily". */
    public const DAILY_MAX_DAYS = 120;

    /** Up to this many days the default is daily; then weekly up to WEEKLY_DEFAULT_MAX_DAYS. */
    public const DAILY_DEFAULT_MAX_DAYS = 31;

    public const WEEKLY_DEFAULT_MAX_DAYS = 180;

    public const DAY_KEY = 'Y-m-d';

    /** A chart's preferred grain (e.g. weekly bars) only applies up to this window. */
    public const PREFERRED_MAX_DAYS = 92;

    public static function defaultFor(Period $period): self
    {
        return match (true) {
            $period->days() <= self::DAILY_DEFAULT_MAX_DAYS => self::DAILY,
            $period->days() <= self::WEEKLY_DEFAULT_MAX_DAYS => self::WEEKLY,
            default => self::MONTHLY,
        };
    }

    /** The options a toggle offers for this period, in display order. @return list<self> */
    public static function optionsFor(Period $period): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $g): bool => $g !== self::DAILY || $period->days() <= self::DAILY_MAX_DAYS,
        ));
    }

    /**
     * A requested value; else the chart's preferred default when the period
     * offers it; else the period's default.
     */
    public static function resolve(?string $value, Period $period, ?self $preferred = null): self
    {
        $options = self::optionsFor($period);
        $wanted = self::tryFrom((string) $value);
        if ($wanted !== null && in_array($wanted, $options, true)) {
            return $wanted;
        }
        if ($preferred !== null && in_array($preferred, $options, true) && $period->days() > 7 && $period->days() <= self::PREFERRED_MAX_DAYS) {
            return $preferred;
        }

        return self::defaultFor($period);
    }

    public function labelKey(): string
    {
        return 'analytics.grain.'.$this->value;
    }

    /**
     * The buckets of a period, oldest first.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public function buckets(Period $period): array
    {
        $out = [];
        $cursor = $period->start()->startOfDay();
        $last = $period->end()->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $bucketEnd = match ($this) {
                self::DAILY => $cursor,
                self::WEEKLY => $cursor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
                self::MONTHLY => $cursor->endOfMonth()->startOfDay(),
            };
            if ($bucketEnd->greaterThan($last)) {
                $bucketEnd = $last;
            }

            $out[] = [
                'start' => $cursor,
                'end' => $bucketEnd->endOfDay(),
                'label' => $this->label($cursor, $bucketEnd),
            ];
            $cursor = $bucketEnd->addDay();
        }

        return $out;
    }

    /**
     * Map every day of the period to its bucket index — the lookup rollUp()
     * and the in-memory movement walkers use.
     *
     * @return array<string, int> 'Y-m-d' => bucket index
     */
    public function dayIndex(Period $period): array
    {
        $map = [];
        foreach ($this->buckets($period) as $i => $bucket) {
            for ($d = $bucket['start']; $d->lessThanOrEqualTo($bucket['end']); $d = $d->addDay()) {
                $map[$d->format(self::DAY_KEY)] = $i;
            }
        }

        return $map;
    }

    /**
     * Fold per-day totals (as SQL returns them: 'Y-m-d' => number) into one
     * value per bucket. Days outside the period are ignored.
     *
     * @param  array<string, float|int>  $byDay
     * @return list<float>
     */
    public function rollUp(Period $period, array $byDay): array
    {
        $index = $this->dayIndex($period);
        $out = array_fill(0, count($this->buckets($period)), 0.0);

        foreach ($byDay as $day => $value) {
            $i = $index[substr((string) $day, 0, 10)] ?? null;
            if ($i !== null) {
                $out[$i] += (float) $value;
            }
        }

        return $out;
    }

    private function label(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $locale = app()->getLocale();

        return match ($this) {
            self::DAILY => $start->locale($locale)->translatedFormat('j M'),
            self::WEEKLY => $start->isSameDay($end)
                ? $start->locale($locale)->translatedFormat('j M')
                : ($start->month === $end->month
                    ? $start->locale($locale)->translatedFormat('j').' – '.$end->locale($locale)->translatedFormat('j M')
                    : $start->locale($locale)->translatedFormat('j M').' – '.$end->locale($locale)->translatedFormat('j M')),
            self::MONTHLY => $start->locale($locale)->translatedFormat('M Y'),
        };
    }
}
