<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Forecast\ForecastQuery;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\Delta;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;

/**
 * Forecast (sketch: Forecast.dc.html; spec §7) — expected recurring revenue
 * for the next 30 / 60 / 90 days, from today (the shell's date range does not
 * apply: a forecast looks forward). ForecastQuery holds the method and the
 * maths; this class shapes and translates.
 *
 * The band is the 95% interval of each kind's historical success rate — NOT
 * the sketch's "±6% vs past forecasts": LETS stores no past forecasts to score,
 * so the band says how sure the rate is, and the copy says exactly that.
 */
final class Forecast extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Forecast.dc.html';

    public const VIEW = 'filament.pages.analytics.forecast.overview';

    public const LANG = 'analytics/forecast_overview.';

    public const FILTERS = [Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const CHART = 'forecast_weekly';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $f = (new ForecastQuery($context))->get();
        $today = CarbonImmutable::today();
        $pooled = $f['rates']['pooled'];

        return [
            'has_data' => $f['has_data'],
            'meta' => __(self::LANG.'meta', [
                'span' => Period::spanLabel($today, $today->addDays(ForecastQuery::HORIZON_DAYS - 1)),
                'active' => ChartFormat::value($f['active']),
            ]).' · '.($pooled === null
                ? __(self::LANG.'meta_no_rate')
                : __(self::LANG.'meta_rate', ['rate' => $this->pct($pooled['rate']), 'n' => ChartFormat::value($pooled['n'])])),
            'kpis' => $this->kpis($f),
            'chart' => $this->chart($f),
            'kinds' => $this->kinds($f),
        ];
    }

    public function export(Context $context): ?array
    {
        $f = (new ForecastQuery($context))->get();

        return [
            'headers' => array_map(static fn (string $k): string => __(self::LANG.'export.'.$k), ['week_start', 'week_end', 'orders', 'scheduled', 'expected', 'low', 'high', 'last_year']),
            'rows' => array_map(static fn (array $w): array => [
                $w['start']->format('Y-m-d'), $w['end']->format('Y-m-d'), $w['orders'],
                number_format($w['scheduled'], 2, '.', ''),
                $w['expected'] === null ? '' : number_format($w['expected'], 2, '.', ''),
                $w['low'] === null ? '' : number_format($w['low'], 2, '.', ''),
                $w['high'] === null ? '' : number_format($w['high'], 2, '.', ''),
                number_format($w['last_year'], 2, '.', ''),
            ], $f['weeks']),
        ];
    }

    /** A forecast is an estimate: whole shekels, never agorot. */
    private static function money(float $value): string
    {
        return ChartFormat::money(round($value));
    }

    private function pct(float $rate): string
    {
        return ChartFormat::value(round($rate * 100, 1), ChartFormat::PERCENT);
    }

    /** @return list<array<string, mixed>> */
    private function kpis(array $f): array
    {
        $out = [];
        foreach (ForecastQuery::HORIZONS as $days) {
            $h = $f['horizons'][$days];
            $hasRate = $h['expected'] !== null;
            $out[] = [
                'label' => __(self::LANG.'kpi.expected', ['days' => $days]),
                'value' => ! $f['has_data'] ? null : self::money($hasRate ? $h['expected'] : $h['scheduled']),
                'empty' => $f['has_data'] ? null : 'no_data',
                'compare' => ! $f['has_data'] ? null : ($hasRate
                    ? __(self::LANG.'caption.orders', ['orders' => ChartFormat::value($h['orders']), 'scheduled' => self::money($h['scheduled'])])
                        .' · '.__(self::LANG.'caption.band', ['low' => self::money($h['low']), 'high' => self::money($h['high'])])
                    : __(self::LANG.'caption.no_rate', ['orders' => ChartFormat::value($h['orders'])])),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> props for rc.chart.combo (expected + upside band, last year as the line) */
    private function chart(array $f): array
    {
        $locale = app()->getLocale();
        $weeks = $f['weeks'];
        $hasRate = $f['rates']['pooled'] !== null;
        $expected = array_map(static fn (array $w): float => (float) ($w['expected'] ?? $w['scheduled']), $weeks);
        $band = array_map(static fn (array $w): float => $w['high'] === null ? 0.0 : max(0.0, round($w['high'] - $w['expected'], 2)), $weeks);
        $total = array_sum($expected);
        $lastYear = $f['last_year_total'];

        $bars = [['key' => 'expected', 'label' => __(self::LANG.($hasRate ? 'chart.expected' : 'chart.scheduled')), 'tone' => 's1', 'values' => $expected]];
        if ($hasRate) {
            $bars[] = ['key' => 'band', 'label' => __(self::LANG.'chart.band'), 'tone' => 's6', 'values' => $band];
        }

        $lastWeek = end($weeks);

        return [
            'id' => self::CHART,
            'labels' => array_map(static fn (array $w): string => $w['start']->locale($locale)->translatedFormat('j M'), $weeks),
            'bars' => $bars,
            'line' => $lastYear > 0 ? ['label' => __(self::LANG.'chart.last_year'), 'tone' => 'ink', 'values' => array_column($weeks, 'last_year'), 'format' => ChartFormat::MONEY] : null,
            'caps' => array_map(static fn (float $v): string => self::money($v), $expected),
            'subtitle' => __(self::LANG.'chart.subtitle', [
                'weeks' => count($weeks),
                'total' => self::money($total),
                'days' => $lastWeek ? $lastWeek['days'] : 0,
                'end' => $lastWeek ? $lastWeek['end']->locale($locale)->translatedFormat('j M') : '',
            ]).($lastYear > 0 ? ' · '.__(self::LANG.'chart.vs_last_year', ['delta' => ChartFormat::signed(Delta::percent($total, $lastYear) ?? 0, ChartFormat::PERCENT)]) : ''),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    private function kinds(array $f): array
    {
        $rows = [];
        foreach (ForecastQuery::KINDS as $kind) {
            $k = $f['by_kind'][$kind];
            $rate = $f['rates']['kinds'][$kind];
            if ($k['orders'] === 0 && ($rate === null || $rate['pooled'])) {
                continue;
            }
            $rows[] = [
                'kind' => __(self::LANG.'kind.'.$kind),
                'attempts' => $rate === null || $rate['pooled'] ? '—' : ChartFormat::value($rate['n']),
                'rate' => $rate === null ? '—' : $this->pct($rate['rate']).($rate['pooled'] ? ' '.__(self::LANG.'table.pooled') : ''),
                'band' => $rate === null ? '—' : $this->pct($rate['low']).' – '.$this->pct($rate['high']),
                'orders' => ChartFormat::value($k['orders']),
                'scheduled' => self::money($k['scheduled']),
                'expected' => $k['expected'] === null ? '—' : self::money($k['expected']),
            ];
        }

        return [
            'columns' => [
                ['key' => 'kind', 'label' => __(self::LANG.'table.kind'), 'strong' => true],
                ['key' => 'attempts', 'label' => __(self::LANG.'table.attempts'), 'numeric' => true],
                ['key' => 'rate', 'label' => __(self::LANG.'table.rate'), 'numeric' => true],
                ['key' => 'band', 'label' => __(self::LANG.'table.band'), 'numeric' => true],
                ['key' => 'orders', 'label' => __(self::LANG.'table.orders'), 'numeric' => true],
                ['key' => 'scheduled', 'label' => __(self::LANG.'table.scheduled'), 'numeric' => true],
                ['key' => 'expected', 'label' => __(self::LANG.'table.expected'), 'numeric' => true],
            ],
            'rows' => $rows,
        ];
    }
}
