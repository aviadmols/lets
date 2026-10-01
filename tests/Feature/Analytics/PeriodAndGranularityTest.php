<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The window math every Analytics query trusts: presets include today, the
 * comparison is the same length immediately before (or a year back), custom
 * ranges are sanitised, and buckets are clipped to the period and roll day
 * totals up without losing or inventing a day.
 */
final class PeriodAndGranularityTest extends TestCase
{
    // === CONSTANTS ===
    private const TODAY = '2026-09-29';

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY);
    }

    public function test_last_30_days_includes_today_and_compares_with_the_30_before(): void
    {
        $p = Period::fromInput('30d', 'previous_period', null, null, $this->today());

        $this->assertSame('2026-08-31', $p->start()->toDateString());
        $this->assertSame('2026-09-29', $p->end()->toDateString());
        $this->assertSame(30, $p->days());
        $this->assertSame('23:59:59', $p->end()->format('H:i:s'));

        $c = $p->comparison();
        $this->assertSame('2026-08-01', $c->start()->toDateString());
        $this->assertSame('2026-08-30', $c->end()->toDateString());
        $this->assertSame(30, $c->days());
        $this->assertSame('2026-08-01', $p->earliest()->toDateString());
    }

    public function test_previous_year_and_none(): void
    {
        $p = Period::fromInput('7d', 'previous_year', null, null, $this->today());
        $this->assertSame('2025-09-23', $p->comparison()->start()->toDateString());
        $this->assertSame('2025-09-29', $p->comparison()->end()->toDateString());

        $none = Period::fromInput('7d', 'none', null, null, $this->today());
        $this->assertFalse($none->hasCompare());
        $this->assertNull($none->comparison());
        $this->assertSame($none->start()->toDateString(), $none->earliest()->toDateString());
    }

    public function test_month_and_year_to_date(): void
    {
        $mtd = Period::fromInput('mtd', null, null, null, $this->today());
        $this->assertSame('2026-09-01', $mtd->start()->toDateString());
        $this->assertSame(29, $mtd->days());

        $ytd = Period::fromInput('ytd', null, null, null, $this->today());
        $this->assertSame('2026-01-01', $ytd->start()->toDateString());
    }

    public function test_custom_range_is_sanitised(): void
    {
        // Reversed dates are swapped; a future end is clipped to today.
        $p = Period::fromInput('custom', null, '2026-12-01', '2026-09-01', $this->today());
        $this->assertSame('2026-09-01', $p->start()->toDateString());
        $this->assertSame('2026-09-29', $p->end()->toDateString());

        // Unreadable → the default window, never an exception.
        $bad = Period::fromInput('custom', null, 'not-a-date', null, $this->today());
        $this->assertSame(Period::DEFAULT_RANGE, $bad->range);

        // An unknown preset or compare falls back too.
        $odd = Period::fromInput('999d', 'sideways', null, null, $this->today());
        $this->assertSame(Period::DEFAULT_RANGE, $odd->range);
        $this->assertSame(Period::DEFAULT_COMPARE, $odd->compare);
    }

    public function test_weekly_buckets_are_clipped_to_the_period_and_cover_every_day(): void
    {
        $p = Period::fromInput('30d', null, null, null, $this->today()); // Mon 31 Aug → Tue 29 Sep
        $buckets = Granularity::WEEKLY->buckets($p);

        $this->assertSame('2026-08-31', $buckets[0]['start']->toDateString());
        $this->assertSame('2026-09-06', $buckets[0]['end']->toDateString());
        $this->assertSame('2026-09-28', end($buckets)['start']->toDateString());
        $this->assertSame('2026-09-29', end($buckets)['end']->toDateString(), 'The last week stops at the period end.');
        $this->assertCount(30, Granularity::WEEKLY->dayIndex($p));
        $this->assertCount(30, Granularity::DAILY->buckets($p));
        $this->assertCount(2, Granularity::MONTHLY->buckets($p)); // Aug (one day) + Sep
    }

    public function test_roll_up_folds_days_into_buckets_and_ignores_days_outside(): void
    {
        $p = Period::fromInput('30d', null, null, null, $this->today());
        $rolled = Granularity::WEEKLY->rollUp($p, [
            '2026-08-31' => 2, '2026-09-06' => 3, // week 1
            '2026-09-07' => 5,                    // week 2
            '2026-09-29' => 1,                    // last (partial) week
            '2026-07-01' => 100,                  // outside — ignored
        ]);

        $this->assertSame([5.0, 5.0, 0.0, 0.0, 1.0], $rolled);
        $this->assertSame(11.0, array_sum($rolled));
    }

    public function test_granularity_defaults_and_options_follow_the_period(): void
    {
        $short = Period::fromInput('30d', null, null, null, $this->today());
        $long = Period::fromInput('ytd', null, null, null, $this->today());

        $this->assertSame(Granularity::DAILY, Granularity::defaultFor($short));
        $this->assertSame(Granularity::MONTHLY, Granularity::defaultFor($long));
        $this->assertNotContains(Granularity::DAILY, Granularity::optionsFor($long), 'A 270-day daily chart is a barcode.');

        // A requested grain wins when offered; daily on a long range falls back.
        $this->assertSame(Granularity::WEEKLY, Granularity::resolve('weekly', $short));
        $this->assertSame(Granularity::MONTHLY, Granularity::resolve('daily', $long));
        // A chart's preferred default applies on short ranges only.
        $this->assertSame(Granularity::WEEKLY, Granularity::resolve(null, $short, Granularity::WEEKLY));
        $this->assertSame(Granularity::MONTHLY, Granularity::resolve(null, $long, Granularity::WEEKLY));
    }

    public function test_context_options_are_constrained(): void
    {
        $context = new Context(Period::default($this->today()), Filters::none(), [], ['unit' => 'revenue', 'bogus' => 'x']);

        $this->assertSame('revenue', $context->option('unit', ['count', 'revenue']));
        $this->assertSame('count', $context->option('unit', ['count']), 'Not allowed → the first (default).');
        $this->assertSame('a', $context->option('missing', ['a', 'b']));
    }

    public function test_filters_are_sanitised(): void
    {
        $f = Filters::fromInput([
            'plans' => ['3', 'x', '3', '12'],
            'frequencies' => ['m1', 'weekly', 'd14'],
            'products' => 'p-1',
            'country' => ['IL'],
            'evil' => ['1'],
        ]);

        $this->assertSame(['3', '12'], $f->get(Filters::PLANS));
        $this->assertSame(['d14', 'm1'], $f->get(Filters::FREQUENCIES));
        $this->assertSame(['p-1'], $f->get(Filters::PRODUCTS));
        $this->assertSame([], $f->get(Filters::COUNTRY), 'Country is shown, never applied.');
        $this->assertFalse($f->includesContracts());
        $this->assertNotSame(Filters::none()->key(), $f->key());
    }
}
