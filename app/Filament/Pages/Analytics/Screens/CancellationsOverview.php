<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Cancellations\CancellationLabels;
use App\Domain\Analytics\Cancellations\CancellationsOverviewQuery;
use App\Domain\Analytics\Cancellations\ChurnData;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Support\Delta;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Cancellations › Overview (sketch: Cancellations.dc.html; spec §6.1).
 * CancellationsOverviewQuery computes; this class shapes; the partial draws.
 *
 * Honest gaps (docs/analytics/data-map.md §6): "Upcoming order contribution"
 * needs the next order date on the cancel event — rendered not-tracked.
 * Reasons and channels are grouped conservatively (CancellationReasons):
 * our own markers are channels, never invented motives.
 */
final class CancellationsOverview extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Cancellations.dc.html';

    public const VIEW = 'filament.pages.analytics.cancellations.overview';

    public const LANG = 'analytics/cancellations_overview.';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTIONS = ['trend' => ['churn', 'cancellation', 'orders', 'mrr']];

    /** Trend grains offered (a daily churn rate is noise). */
    public const TREND_GRAINS = [Granularity::WEEKLY, Granularity::MONTHLY];

    /** Donut / line tones in series order; s6 is "Other". */
    public const TONES = ['s4', 's3', 's1', 's2', 's5', 's6'];

    public const MAX_SLICES = 5;

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new CancellationsOverviewQuery($context))->get();
        $compare = SubscribersOverview::compareCaption($context->period);

        return [
            'has_data' => $q['has_data'],
            'subscriber_kpis' => $this->subscriberKpis($q, $compare),
            'subscription_kpis' => $this->subscriptionKpis($q, $compare),
            'trend' => $this->trend($q, $context),
            'products' => $this->products($q['products']),
            'order_wise' => $this->orderWise($q['order_wise']),
            'reasons' => $this->slices(array_map(static fn (array $r): array => [
                'label' => CancellationLabels::reason($r['key'], $r['text']), 'value' => $r['count'],
            ], $q['reasons'])),
            'channels' => $this->slices(array_map(static fn (array $r): array => [
                'label' => CancellationLabels::channel($r['key']), 'value' => $r['count'],
            ], $q['channels'])),
            'cancelled' => ChartFormat::value($q['subscription']['cancelled']),
            'reason_trend' => $this->reasonTrend($q, $context),
            'by_frequency' => $this->dimension($q['by_frequency'], static fn (array $r): string => CancellationLabels::frequency($r['key'])),
            'by_plan' => $this->dimension($q['by_plan'], static fn (array $r): string => CancellationLabels::sellingPlan($r['key'], $r['name'])),
        ];
    }

    /** The cancellation log of the period. */
    public function export(Context $context): ?array
    {
        $period = $context->period;
        $rows = ChurnData::inWindow((new ChurnData($context))->cancellations($period->start(), $period->end()), $period);

        return [
            'headers' => array_map(static fn (string $k): string => __(self::LANG.'export.'.$k), ['date', 'subscription', 'customer', 'product', 'frequency', 'mrr', 'orders', 'channel', 'reason']),
            'rows' => array_map(static fn (array $r): array => [
                substr($r['at'], 0, 16),
                $r['ref'] !== '' ? $r['ref'] : $r['sub'],
                $r['name'] !== '' ? $r['name'] : $r['email'],
                CancellationLabels::product($r['product'], $r['product_title']),
                CancellationLabels::frequency($r['freq']),
                number_format($r['mrr'], 2, '.', ''),
                $r['orders'],
                CancellationLabels::channel($r['channel']),
                CancellationLabels::reason($r['reason_key'], $r['reason']),
            ], $rows),
        ];
    }

    /** @return array<string, string> the trend toggle's grain options */
    public static function trendGrains(): array
    {
        $out = [];
        foreach (self::TREND_GRAINS as $grain) {
            $out[$grain->value] = __($grain->labelKey());
        }

        return $out;
    }

    // === Shaping ===

    /** @return list<array<string, mixed>> */
    private function subscriberKpis(array $q, ?string $compare): array
    {
        $s = $q['subscriber'];
        $p = $q['subscriber_previous'];

        return [
            [
                'label' => __(self::LANG.'kpi.churn_rate'),
                'value' => $s['rate'] === null ? null : ChartFormat::value($s['rate'], ChartFormat::PERCENT),
                'delta' => $p ? Delta::points($s['rate'], $p['rate']) : null, 'unit' => 'points', 'goodUp' => false,
                'compare' => __(self::LANG.'caption.of_start', ['n' => ChartFormat::value($s['lost']), 'start' => ChartFormat::value($s['start_active'])]),
            ],
            [
                'label' => __(self::LANG.'kpi.lost'),
                'value' => ChartFormat::value($s['lost']),
                'delta' => $p ? Delta::percent($s['lost'], $p['lost']) : null, 'unit' => 'percent', 'goodUp' => false,
                'compare' => $compare,
            ],
            [
                'label' => __(self::LANG.'kpi.zero_day'),
                'value' => $s['zero_day_share'] === null ? null : ChartFormat::value($s['zero_day_share'], ChartFormat::PERCENT),
                'delta' => $p ? Delta::points($s['zero_day_share'], $p['zero_day_share']) : null, 'unit' => 'points', 'goodUp' => false,
                'compare' => __(self::LANG.'caption.zero_day', ['n' => ChartFormat::value($s['zero_day']), 'lost' => ChartFormat::value($s['lost'])]),
            ],
            [
                'label' => __(self::LANG.'kpi.upcoming'),
                'value' => null, 'delta' => null, 'unit' => 'points', 'goodUp' => false, 'compare' => null, 'empty' => 'not_tracked',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function subscriptionKpis(array $q, ?string $compare): array
    {
        $s = $q['subscription'];
        $p = $q['subscription_previous'];

        return [
            [
                'label' => __(self::LANG.'kpi.cancellation_rate'),
                'value' => $s['rate'] === null ? null : ChartFormat::value($s['rate'], ChartFormat::PERCENT),
                'delta' => $p ? Delta::points($s['rate'], $p['rate']) : null, 'unit' => 'points', 'goodUp' => false,
                'compare' => __(self::LANG.'caption.of_start', ['n' => ChartFormat::value($s['cancelled']), 'start' => ChartFormat::value($s['start_active'])]),
            ],
            [
                'label' => __(self::LANG.'kpi.cancelled'),
                'value' => ChartFormat::value($s['cancelled']),
                'delta' => $p ? Delta::percent($s['cancelled'], $p['cancelled']) : null, 'unit' => 'percent', 'goodUp' => false,
                'compare' => $compare,
            ],
            [
                'label' => __(self::LANG.'kpi.orders_before'),
                'value' => $s['orders_before'] === null ? null : ChartFormat::value($s['orders_before']),
                'delta' => $p ? Delta::percent($s['orders_before'], $p['orders_before']) : null, 'unit' => 'percent', 'goodUp' => true,
                'compare' => __(self::LANG.'caption.orders_avg'),
            ],
            [
                'label' => __(self::LANG.'kpi.mrr_lost'),
                'value' => ChartFormat::money($s['mrr_lost']),
                'delta' => $p ? Delta::percent($s['mrr_lost'], $p['mrr_lost']) : null, 'unit' => 'percent', 'goodUp' => false,
                'compare' => $s['mrr_share'] === null ? $compare : __(self::LANG.'caption.mrr_share', ['share' => ChartFormat::value($s['mrr_share'], ChartFormat::PERCENT)]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function trend(array $q, Context $context): array
    {
        $t = $q['trend'];
        $metric = $context->option('trend', self::OPTIONS['trend']);

        [$bars, $line, $format] = match ($metric) {
            'cancellation' => [
                ['key' => 'cancelled', 'label' => __(self::LANG.'trend.series.cancelled'), 'tone' => 's4', 'values' => $t['cancelled']],
                ['label' => __(self::LANG.'trend.series.cancellation_rate'), 'tone' => 'ink', 'values' => $t['cancellation_rate'], 'format' => ChartFormat::PERCENT],
                ChartFormat::NUMBER,
            ],
            'orders' => [
                ['key' => 'cancelled', 'label' => __(self::LANG.'trend.series.cancelled'), 'tone' => 's6', 'values' => $t['cancelled']],
                ['label' => __(self::LANG.'trend.series.orders_before'), 'tone' => 'ink', 'values' => $t['orders_before'], 'format' => ChartFormat::NUMBER],
                ChartFormat::NUMBER,
            ],
            'mrr' => [
                ['key' => 'mrr_lost', 'label' => __(self::LANG.'trend.series.mrr_lost'), 'tone' => 's3', 'values' => $t['mrr_lost']],
                null,
                ChartFormat::MONEY,
            ],
            default => [
                ['key' => 'lost', 'label' => __(self::LANG.'trend.series.lost'), 'tone' => 's4', 'values' => $t['lost']],
                ['label' => __(self::LANG.'trend.series.churn_rate'), 'tone' => 'ink', 'values' => $t['churn_rate'], 'format' => ChartFormat::PERCENT],
                ChartFormat::NUMBER,
            ],
        };

        $metrics = [];
        foreach (self::OPTIONS['trend'] as $value) {
            $metrics[$value] = __(self::LANG.'trend.metric.'.$value);
        }

        return [
            'id' => CancellationsOverviewQuery::CHART_TREND,
            'labels' => $t['labels'],
            'bars' => [$bars],
            'line' => $line,
            'format' => $format,
            'metric' => $metric,
            'metrics' => $metrics,
            'grain' => $q['trend_grain']->value,
            'grains' => self::trendGrains(),
            'subtitle' => __(self::LANG.'trend.subtitle.'.$q['trend_grain']->value, [
                'n' => $q['trend_grain'] === Granularity::MONTHLY ? CancellationsOverviewQuery::TREND_MONTHS : CancellationsOverviewQuery::TREND_WEEKS,
            ]),
        ];
    }

    /** @return list<array<string, mixed>> rc.chart.hbars rows */
    private function products(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'label' => CancellationLabels::product($r['product'], $r['title']),
            'value' => $r['cancelled_mrr'],
            'display' => __(self::LANG.'products.display', [
                'mrr' => ChartFormat::money($r['cancelled_mrr']),
                'share' => ChartFormat::value($r['share'], ChartFormat::PERCENT),
            ]),
            'tone' => 's4',
        ], $rows);
    }

    /** @return array<string, mixed> props for a stacked rc.chart.bars */
    private function orderWise(array $rows): array
    {
        $labels = array_map(static fn (array $r): string => $r['bucket'] >= CancellationsOverviewQuery::ORDER_BUCKETS
            ? __(self::LANG.'order_wise.bucket_plus', ['n' => $r['bucket']])
            : (string) $r['bucket'], $rows);

        return [
            'labels' => $labels,
            'bars' => [
                ['key' => 'active', 'label' => __(self::LANG.'order_wise.active'), 'tone' => 's1', 'values' => array_column($rows, 'active')],
                ['key' => 'cancelled', 'label' => __(self::LANG.'order_wise.cancelled'), 'tone' => 's4', 'values' => array_column($rows, 'cancelled')],
            ],
            'caps' => array_map(static fn (array $r): string => $r['share'] === null ? '' : ChartFormat::value($r['share'], ChartFormat::PERCENT), $rows),
            'has_data' => array_sum(array_column($rows, 'cancelled')) + array_sum(array_column($rows, 'active')) > 0,
        ];
    }

    /**
     * @param  list<array{label: string, value: int}>  $rows  biggest first
     * @return list<array<string, mixed>>
     */
    private function slices(array $rows): array
    {
        $out = [];
        foreach (array_slice($rows, 0, self::MAX_SLICES) as $i => $r) {
            $out[] = [...$r, 'tone' => self::TONES[$i]];
        }
        $rest = array_slice($rows, self::MAX_SLICES);
        if ($rest !== []) {
            $out[] = ['label' => __('analytics.other'), 'value' => array_sum(array_column($rest, 'value')), 'tone' => 's6'];
        }

        return $out;
    }

    /** @return array<string, mixed> props for rc.chart.line */
    private function reasonTrend(array $q, Context $context): array
    {
        $t = $q['reason_trend'];
        $series = [];
        foreach ($t['series'] as $i => $s) {
            $series[] = [
                'key' => 'r'.$i,
                'label' => CancellationLabels::reason($s['key'], $s['text']),
                'tone' => $s['key'] === CancellationsOverviewQuery::OTHER ? 's6' : (self::TONES[$i] ?? 's6'),
                'values' => $s['values'],
            ];
        }

        return [
            'id' => CancellationsOverviewQuery::CHART_REASON_TREND,
            'labels' => $t['labels'],
            'series' => $series,
            'grain' => $q['reason_grain']->value,
            'grains' => self::trendGrains(),
        ];
    }

    /**
     * @param  callable(array): string  $label
     * @return list<array<string, mixed>> rc.chart.hbars rows
     */
    private function dimension(array $rows, callable $label): array
    {
        return array_map(static fn (array $r): array => [
            'label' => $label($r),
            'value' => $r['cancelled'],
            'display' => __(self::LANG.'dimension.row', [
                'n' => ChartFormat::value($r['cancelled']),
                'base' => ChartFormat::value($r['active'] + $r['cancelled']),
                'share' => ChartFormat::value($r['share'] ?? 0, ChartFormat::PERCENT),
            ]),
            'tone' => 's4',
        ], $rows);
    }
}
