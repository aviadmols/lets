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

    /** Months nobody joined in: hidden by default, so the table reads only real cohorts. */
    public const EMPTY_HIDE = 'hide';

    public const EMPTY_SHOW = 'show';

    public const OPTIONS = [
        'metric' => CohortsQuery::OPTIONS,
        'span' => CohortsQuery::SPANS,
        'mode' => [self::MODE_PERCENT, self::MODE_NUMBER],
        'empty' => [self::EMPTY_HIDE, self::EMPTY_SHOW],
    ];

    /** A cohort smaller than this is too few people to read a trend from — marked, not hidden. */
    public const SMALL_COHORT = 20;

    /** Retention bands of the churn table (percent still active). */
    public const BAND_HIGH = 90;

    public const BAND_MID = 60;

    /** The headline KPIs: retained after this many months. */
    public const KPI_MONTHS = [1, 2, 3];

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
            // Retention reads as churn: where people leave, not only who stayed.
            'churn' => $q['metric'] === CohortsQuery::METRIC_RETENTION && $q['has_data']
                ? self::churn($q, $mode, $context->option('empty', self::OPTIONS['empty']) === self::EMPTY_HIDE)
                : null,
            'empty' => $context->option('empty', self::OPTIONS['empty']),
        ];
    }

    /**
     * Retention turned into churn: in which month people leave (all cohorts
     * together), the share still active after 1/2/3 months, a retention curve,
     * and a table whose every cell says both who stayed and how many left.
     *
     * Aggregates are WEIGHTED by people: month m sums the members of every
     * cohort old enough to have a month m, so one big cohort is not drowned by
     * a few tiny ones. Like the grid, a cohort's newest month is read as of today.
     *
     * @param  array{level: string, columns: int, rows: list<array{month: string, size: int, values: list<int|float>, cells: list<?float>}>}  $q
     * @return array<string, mixed>
     */
    public static function churn(array $q, string $mode, bool $hideEmpty): array
    {
        $l = self::LANG.'churn.';
        $last = max(0, $q['columns'] - 1);
        $stillIn = array_fill(0, $last + 1, 0);  // members active at month m, summed
        $startedAt = array_fill(0, $last + 1, 0); // members of cohorts that have reached month m
        $left = array_fill(0, $last + 1, 0);      // left during month m (between m-1 and m)
        $before = array_fill(0, $last + 1, 0);    // active at m-1, in cohorts that reached m
        $joined = 0;
        $gone = 0;

        foreach ($q['rows'] as $r) {
            if ($r['size'] === 0) {
                continue;
            }
            $joined += $r['size'];
            $gone += $r['size'] - (int) end($r['values']);
            foreach ($r['values'] as $m => $v) {
                $stillIn[$m] += (int) $v;
                $startedAt[$m] += $r['size'];
                if ($m > 0) {
                    $left[$m] += max(0, (int) $r['values'][$m - 1] - (int) $v);
                    $before[$m] += (int) $r['values'][$m - 1];
                }
            }
        }

        $pct = static fn (int|float $part, int|float $whole): ?float => $whole > 0 ? round($part / $whole * 100, 1) : null;
        $fmtPct = static fn (?float $p): string => $p === null ? '—' : ChartFormat::value($p, ChartFormat::PERCENT);
        $people = __(self::LANG.'size.'.$q['level']);

        // The month most people leave in — the sentence the screen opens with.
        $worst = null;
        for ($m = 1; $m <= $last; $m++) {
            if ($left[$m] > 0 && ($worst === null || $left[$m] > $left[$worst])) {
                $worst = $m;
            }
        }
        $totalLeft = array_sum($left);
        $insight = $worst === null ? null : [
            'title' => __($l.'insight.title', ['n' => $worst]),
            'body' => __($l.'insight.body', [
                'left' => ChartFormat::value($left[$worst]),
                'base' => ChartFormat::value($before[$worst]),
                'pct' => $fmtPct($pct($left[$worst], $before[$worst])),
                'n' => $worst,
                'rest' => ChartFormat::value($totalLeft - $left[$worst]),
            ]),
        ];

        $kpis = [];
        foreach (self::KPI_MONTHS as $m) {
            $kpis[] = [
                'label' => trans_choice($l.'kpi.after', $m, ['count' => $m]),
                'value' => $m <= $last && $startedAt[$m] > 0 ? $fmtPct($pct($stillIn[$m], $startedAt[$m])) : null,
                'note' => $m <= $last && $startedAt[$m] > 0 ? __($l.'kpi.left_in', ['count' => ChartFormat::value($left[$m]), 'n' => $m]) : null,
            ];
        }
        $kpis[] = [
            'label' => __($l.'kpi.gone'),
            'value' => ChartFormat::value($gone),
            'note' => __($l.'kpi.gone_note', ['pct' => $fmtPct($pct($gone, $joined)), 'joined' => ChartFormat::value($joined), 'people' => $people]),
        ];

        $drops = [];
        for ($m = 1; $m <= $last; $m++) {
            if ($before[$m] === 0) {
                continue;
            }
            $drops[] = [
                'label' => __($l.'drops.month', ['n' => $m]),
                'value' => $left[$m],
                'display' => __($l.'drops.value', ['count' => ChartFormat::value($left[$m]), 'pct' => $fmtPct($pct($left[$m], $before[$m]))]),
                'tone' => 's3',
            ];
        }

        // The curve stops where too few people have got that far to read a trend —
        // two members of an old cohort must not drag its tail to 50%. A store whose
        // every cohort is small still sees its whole curve.
        $floor = $joined >= self::SMALL_COHORT ? self::SMALL_COHORT : 1;
        $curve = [];
        $curveLabels = [];
        for ($m = 0; $m <= $last; $m++) {
            if ($startedAt[$m] < $floor) {
                break;
            }
            $curveLabels[] = __(self::LANG.'month', ['n' => $m]);
            $curve[] = $pct($stillIn[$m], $startedAt[$m]);
        }

        return [
            'insight' => $insight,
            'kpis' => $kpis,
            'drops' => $drops,
            'curve' => ['labels' => $curveLabels, 'series' => [[
                'key' => 'retained', 'label' => __($l.'curve.series'), 'tone' => 's1', 'values' => $curve, 'area' => true,
            ]]],
            'table' => self::churnTable($q, $mode, $hideEmpty),
        ];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, people: string} */
    private static function churnTable(array $q, string $mode, bool $hideEmpty): array
    {
        $l = self::LANG.'churn.table.';
        // Columns stop at the oldest month a cohort WITH people has reached — an
        // empty old month must not stretch the table into eleven blank columns.
        $last = 0;
        foreach ($q['rows'] as $r) {
            if ($r['size'] > 0 || ! $hideEmpty) {
                $last = max($last, count($r['values']) - 1);
            }
        }
        $locale = app()->getLocale();
        $columns = [];
        for ($m = 1; $m <= $last; $m++) {
            $columns[] = __(self::LANG.'month', ['n' => $m]);
        }
        $current = CarbonImmutable::today()->format('Y-m');

        $rows = [];
        foreach ($q['rows'] as $r) {
            if ($hideEmpty && $r['size'] === 0) {
                continue;
            }
            $small = $r['size'] > 0 && $r['size'] < self::SMALL_COHORT;
            $cells = [];
            for ($m = 1; $m <= $last; $m++) {
                if (! array_key_exists($m, $r['values']) || $r['size'] === 0) {
                    $cells[] = ['band' => 'empty', 'main' => '', 'left' => ''];

                    continue;
                }
                $kept = (int) $r['values'][$m];
                $share = round($kept / $r['size'] * 100, 1);
                $gone = max(0, (int) $r['values'][$m - 1] - $kept);
                $cells[] = [
                    'band' => $small ? 'small' : ($share >= self::BAND_HIGH ? 'high' : ($share >= self::BAND_MID ? 'mid' : 'low')),
                    'main' => $mode === self::MODE_NUMBER ? ChartFormat::value($kept) : ChartFormat::value($share, ChartFormat::PERCENT),
                    'left' => $gone === 0 ? __($l.'none_left') : trans_choice($l.'left', $gone, ['count' => ChartFormat::value($gone)]),
                ];
            }
            $churned = $r['size'] - (int) end($r['values']);
            $rows[] = [
                'label' => CarbonImmutable::parse($r['month'].'-01')->locale($locale)->translatedFormat('F Y'),
                'flag' => $r['month'] === $current && $r['size'] > 0 ? __($l.'current') : ($small ? __($l.'small') : null),
                'size' => ChartFormat::value($r['size']),
                'cells' => $cells,
                'churned' => $r['size'] === 0 ? '–' : ChartFormat::value($churned),
                'churned_pct' => $r['size'] > 0 && $churned > 0 ? ChartFormat::value(round($churned / $r['size'] * 100, 1), ChartFormat::PERCENT) : null,
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'people' => __(self::LANG.'size.'.$q['level'])];
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
