<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Cohorts\CohortsQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;

/**
 * Cohorts (sketch: Cohorts.dc.html; spec §2). A heatmap of joining months ×
 * months since joining, for one of ten metrics (subscriber / subscription ×
 * retention, orders placed, avg cumulative orders, revenue realized, avg LTV),
 * as % or #. CohortsQuery computes; this class shapes; the partial draws.
 *
 * Every metric is backed by data (data-map §2). The one honest limitation —
 * a Shopify-Payments contract's checkout order is not a LETS billing attempt —
 * is said under the grid whenever contracts are in the cohorts.
 *
 * The cohort window (3–36 months, YTD) is the board's own selector, not the
 * page's date range: a cohort table reads joining MONTHS.
 */
final class Cohorts extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Cohorts.dc.html';

    public const VIEW = 'filament.pages.analytics.cohorts.overview';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const MODE_PERCENT = 'percent';

    public const MODE_NUMBER = 'number';

    public const OPTIONS = [
        'metric' => CohortsQuery::OPTIONS,
        'span' => CohortsQuery::SPANS,
        'mode' => [self::MODE_PERCENT, self::MODE_NUMBER],
    ];

    public const LANG = 'analytics/cohorts_overview.';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new CohortsQuery($context))->get();
        $option = $context->option('metric', self::OPTIONS['metric']);
        $mode = $context->option('mode', self::OPTIONS['mode']);
        $cumulative = in_array($q['metric'], CohortsQuery::CUMULATIVE, true);
        $showValues = $mode === self::MODE_NUMBER || $cumulative;

        $menu = [];
        foreach (CohortsQuery::OPTIONS as $value) {
            [$level, $metric] = CohortsQuery::split($value);
            $menu[$level][$value] = __(self::LANG.'metric.'.$metric.'.'.$level);
        }

        return [
            'has_data' => $q['has_data'],
            'metric' => $option,
            'metric_label' => __(self::LANG.'metric.'.$q['metric'].'.'.$q['level']),
            'menu' => [
                __(self::LANG.'level.subscriber') => $menu[CohortsQuery::LEVEL_SUBSCRIBER],
                __(self::LANG.'level.subscription') => $menu[CohortsQuery::LEVEL_SUBSCRIPTION],
            ],
            'span' => $context->option('span', self::OPTIONS['span']),
            'spans' => array_combine(CohortsQuery::SPANS, array_map(
                static fn (string $s): string => $s === 'ytd' ? __(self::LANG.'span.ytd') : __(self::LANG.'span.months', ['count' => $s]),
                CohortsQuery::SPANS,
            )),
            'mode' => $mode,
            'modes' => [self::MODE_PERCENT => '%', self::MODE_NUMBER => '#'],
            'cumulative' => $cumulative,
            'title' => __(self::LANG.'title', ['metric' => __(self::LANG.'metric.'.$q['metric'].'.'.$q['level'])]),
            'heat' => $this->heat($q, $showValues),
            'size_label' => __(self::LANG.'size.'.$q['level']),
            'formula' => __(self::LANG.'formula.'.$q['metric']),
            'contracts_note' => $q['has_contracts'] && $q['metric'] !== CohortsQuery::METRIC_RETENTION
                ? __(self::LANG.'contracts_note') : null,
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new CohortsQuery($context))->get();
        $headers = [__('analytics.table.cohort'), __(self::LANG.'size.'.$q['level'])];
        for ($m = 0; $m < $q['columns']; $m++) {
            $headers[] = __(self::LANG.'month', ['n' => $m]);
        }

        return [
            'headers' => $headers,
            'rows' => array_map(static fn (array $r): array => [$r['month'], $r['size'], ...$r['values']], $q['rows']),
        ];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>} rc.chart.heatmap props */
    private function heat(array $q, bool $showValues): array
    {
        $columns = [];
        for ($m = 0; $m < $q['columns']; $m++) {
            $columns[] = __(self::LANG.'month', ['n' => $m]);
        }
        $money = in_array($q['metric'], [CohortsQuery::METRIC_REVENUE, CohortsQuery::METRIC_LTV], true);
        $locale = app()->getLocale();

        $rows = array_map(static function (array $r) use ($showValues, $money, $locale): array {
            $row = [
                'label' => CarbonImmutable::parse($r['month'].'-01')->locale($locale)->translatedFormat('M Y'),
                'size' => ChartFormat::value($r['size']),
                'cells' => $r['cells'],
            ];
            if ($showValues || $r['size'] === 0) {
                $row['display'] = array_map(
                    static fn ($v): string => $r['size'] === 0 ? '–' : ($money ? ChartFormat::money((float) $v) : ChartFormat::value($v)),
                    $r['values'],
                );
            } else {
                $row['display'] = array_map(static fn ($c): string => $c === null ? '–' : ChartFormat::value($c, ChartFormat::PERCENT), $r['cells']);
            }

            return $row;
        }, $q['rows']);

        return ['columns' => $columns, 'rows' => $rows];
    }
}
