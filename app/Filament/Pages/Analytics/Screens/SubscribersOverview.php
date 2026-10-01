<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Subscribers\MovementSummary;
use App\Domain\Analytics\Subscribers\SubscribersOverviewQuery;
use App\Domain\Analytics\Support\Delta;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Subscribers › Overview — the reference screen (sketch: Main.dc.html /
 * MainHe.dc.html; spec §1.1). SubscribersOverviewQuery computes; this class
 * shapes the numbers into the props of the rc.chart.* components; the partial
 * only draws.
 *
 * Honest gaps (docs/analytics/data-map.md): "Upcoming-order churn", upsell
 * additions to subscriptions and quantity increases/decreases have no source
 * in LETS yet — their cells render the not-tracked state, never a zero.
 */
final class SubscribersOverview extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Main.dc.html';

    public const VIEW = 'filament.pages.analytics.subscribers.overview';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    /** Movement type → series tone (brief §2: additions blue/teal/violet, reductions amber/red/gray). */
    public const TONES = [
        MovementLog::NEW => 's1',
        MovementLog::REACTIVATED => 's2',
        MovementLog::RESUMED => 's5',
        MovementLog::PAUSED => 's3',
        MovementLog::CANCELLED => 's4',
        MovementLog::EXPIRED => 's6',
    ];

    /** Donut slice tones, in slice order. */
    public const SLICE_TONES = ['s1', 's2', 's3', 's5', 's4', 's6'];

    /** A donut shows this many slices; the rest fold into "Other". */
    public const MAX_SLICES = 5;

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new SubscribersOverviewQuery($context))->get();
        $compare = self::compareCaption($context->period);

        return [
            'has_data' => $q['has_data'],
            'kpis' => $this->kpis($q, $compare),
            'subscribers_trend' => $this->trend($q, SubscribersOverviewQuery::CHART_SUBSCRIBERS, MovementSummary::LEVEL_SUBSCRIBER, $context),
            'subscriptions_trend' => $this->trend($q, SubscribersOverviewQuery::CHART_SUBSCRIPTIONS, MovementSummary::LEVEL_SUBSCRIPTION, $context),
            'subscribers_activity' => $this->subscriberActivity($q),
            'subscriptions_activity' => $this->subscriptionActivity($q),
            'by_frequency' => $this->slices($q['by_frequency']),
            'by_plan' => $this->slices($q['by_plan']),
            'plan_count' => count($q['by_plan']),
            'active_subscribers' => ChartFormat::value($q['kpis']['subscribers']['value']),
            'plan_table' => $this->planTable($q),
            'products_activity' => $this->productsActivity($q),
            'churn' => $this->churn($q, $context),
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new SubscribersOverviewQuery($context))->get();
        $rows = array_map(static fn (array $r): array => [
            $r['plan'], $r['frequency'], $r['subscribers'], $r['mrr'],
        ], $q['plan_frequency']);

        return [
            'headers' => [
                __('analytics/subscribers_overview.table.plan'),
                __('analytics/subscribers_overview.table.frequency'),
                __('analytics/subscribers_overview.table.subscribers'),
                __('analytics/subscribers_overview.table.mrr'),
            ],
            'rows' => $rows,
        ];
    }

    /** "vs previous 30 days" / "vs 1 – 30 Aug 2026" / "vs same period last year" / null. */
    public static function compareCaption(Period $period): ?string
    {
        $comparison = $period->comparison();
        if ($comparison === null) {
            return null;
        }
        if ($period->compare === Period::COMPARE_PREVIOUS_YEAR) {
            return __('analytics.compare.vs_last_year');
        }
        if (in_array($period->range, [Period::RANGE_7, Period::RANGE_30, Period::RANGE_90], true)) {
            return __('analytics.compare.vs_previous_days', ['days' => $period->days()]);
        }

        return __('analytics.compare.vs_span', ['span' => $comparison->label()]);
    }

    // === Shaping ===

    /** @return list<array<string, mixed>> */
    private function kpis(array $q, ?string $compare): array
    {
        $k = $q['kpis'];
        $card = static fn (string $key, string $value, ?float $delta): array => [
            'label' => __('analytics/subscribers_overview.kpi.'.$key),
            'value' => $value,
            'delta' => $delta,
            'compare' => $compare,
        ];

        return [
            $card('subscribers', ChartFormat::value($k['subscribers']['value']), $k['subscribers']['delta']),
            $card('subscriptions', ChartFormat::value($k['subscriptions']['value']), $k['subscriptions']['delta']),
            $card('quantity', ChartFormat::value($k['quantity']['value']), $k['quantity']['delta']),
            $card('mrr', ChartFormat::money((float) $k['mrr']['value']), $k['mrr']['delta']),
        ];
    }

    /** @return array<string, mixed> props for rc.chart.combo + its toggle */
    private function trend(array $q, string $chartId, string $level, Context $context): array
    {
        $series = $q['series'][$chartId];
        $bars = [];
        foreach (MovementLog::TYPES as $type) {
            $bars[] = [
                'key' => $type,
                'label' => __('analytics.movement.'.$level.'.'.$type),
                'tone' => self::TONES[$type],
                'values' => $series['types'][$type],
                'sign' => MovementLog::direction($type),
            ];
        }

        return [
            'id' => $chartId,
            'labels' => $series['labels'],
            'bars' => $bars,
            'line' => [
                'label' => __('analytics/subscribers_overview.line.'.$level),
                'tone' => 'ink',
                'values' => $series['active'],
            ],
            'grain' => SubscribersOverviewQuery::grain($context, $chartId)->value,
            'grains' => self::grainOptions($context->period),
        ];
    }

    /** @return array<string, string> granularity value => translated label */
    public static function grainOptions(Period $period): array
    {
        $out = [];
        foreach (Granularity::optionsFor($period) as $grain) {
            $out[$grain->value] = __($grain->labelKey());
        }

        return $out;
    }

    /** @return list<array<string, mixed>> rc.chart.numbers rows */
    private function subscriberActivity(array $q): array
    {
        $a = $q['activity'][MovementSummary::LEVEL_SUBSCRIBER];
        $net = $a['additions'] - $a['reductions'];
        $prevNet = $a['previous_additions'] !== null ? $a['previous_additions'] - $a['previous_reductions'] : null;

        $rows = [
            ['label' => __('analytics/subscribers_overview.activity.active'), 'value' => ChartFormat::value($a['active']['value']), 'delta' => $a['active']['delta']],
            ['label' => __('analytics/subscribers_overview.activity.net'), 'value' => ChartFormat::signed($net), 'tone' => $net > 0 ? 'good' : ($net < 0 ? 'bad' : null), 'delta' => Delta::percent($net, $prevNet)],
            ['label' => __('analytics/subscribers_overview.activity.additions'), 'value' => ChartFormat::value($a['additions']), 'kind' => 'group', 'delta' => Delta::percent($a['additions'], $a['previous_additions'])],
        ];
        foreach (MovementLog::ADDITIONS as $type) {
            $rows[] = ['label' => __('analytics.movement.subscriber.'.$type), 'value' => ChartFormat::value($a['counts'][$type])];
        }
        $rows[] = ['label' => __('analytics/subscribers_overview.activity.reductions'), 'value' => ChartFormat::value($a['reductions']), 'kind' => 'group', 'delta' => Delta::percent($a['reductions'], $a['previous_reductions']), 'goodUp' => false];
        foreach (MovementLog::REDUCTIONS as $type) {
            $rows[] = ['label' => __('analytics.movement.subscriber.'.$type), 'value' => ChartFormat::value($a['counts'][$type])];
        }

        return $rows;
    }

    /** @return array<string, mixed> stat-grid cells for the subscriptions activity card */
    private function subscriptionActivity(array $q): array
    {
        $a = $q['activity'][MovementSummary::LEVEL_SUBSCRIPTION];
        $net = $a['additions'] - $a['reductions'];
        $cell = static fn (string $label, string $value, ?float $delta = null, bool $goodUp = true): array => compact('label', 'value', 'delta', 'goodUp');

        $cells = [
            $cell(__('analytics/subscribers_overview.activity.active_subscriptions'), ChartFormat::value($a['active']['value']), $a['active']['delta']),
            $cell(__('analytics/subscribers_overview.activity.additions'), ChartFormat::value($a['additions'])),
            $cell(__('analytics/subscribers_overview.activity.reductions'), ChartFormat::value($a['reductions'])),
        ];
        foreach ([...MovementLog::ADDITIONS, ...MovementLog::REDUCTIONS] as $type) {
            $cells[] = $cell(__('analytics.movement.subscription.'.$type), ChartFormat::value($a['counts'][$type]));
        }

        return [
            'cells' => $cells,
            'net' => ChartFormat::signed($net),
            'net_tone' => $net > 0 ? 'good' : ($net < 0 ? 'bad' : null),
        ];
    }

    /**
     * Top slices + "Other", each with a tone in series order.
     *
     * @param  list<array{label: string, subscribers: int}>  $groups
     * @return list<array<string, mixed>>
     */
    private function slices(array $groups): array
    {
        $top = array_slice($groups, 0, self::MAX_SLICES);
        $rest = array_slice($groups, self::MAX_SLICES);
        $out = [];
        foreach ($top as $i => $g) {
            $out[] = ['label' => $g['label'], 'value' => $g['subscribers'], 'tone' => self::SLICE_TONES[$i] ?? 's6'];
        }
        if ($rest !== []) {
            $out[] = [
                'label' => __('analytics.other'),
                'value' => array_sum(array_column($rest, 'subscribers')),
                'tone' => 's6',
            ];
        }

        return $out;
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function planTable(array $q): array
    {
        $total = max(1, (int) $q['kpis']['subscribers']['value']);
        $rows = array_map(static fn (array $r): array => [
            'plan' => $r['plan'],
            'frequency' => $r['frequency'],
            'subscribers' => ChartFormat::value($r['subscribers']),
            'mrr' => ChartFormat::money($r['mrr']),
            'contribution' => ChartFormat::value(Delta::share($r['subscribers'], $total), ChartFormat::PERCENT),
        ], $q['plan_frequency']);

        return [
            'columns' => [
                ['key' => 'plan', 'label' => __('analytics/subscribers_overview.table.plan'), 'strong' => true],
                ['key' => 'frequency', 'label' => __('analytics/subscribers_overview.table.frequency')],
                ['key' => 'subscribers', 'label' => __('analytics/subscribers_overview.table.subscribers'), 'numeric' => true],
                ['key' => 'mrr', 'label' => __('analytics/subscribers_overview.table.mrr'), 'numeric' => true],
                ['key' => 'contribution', 'label' => __('analytics/subscribers_overview.table.contribution'), 'numeric' => true],
            ],
            'rows' => $rows,
        ];
    }

    /** @return array{additions: list<array<string, mixed>>, reductions: list<array<string, mixed>>, net: string, net_tone: ?string, units: string} */
    private function productsActivity(array $q): array
    {
        $u = $q['quantities'];
        $row = static fn (string $key, ?int $value, string $sign): array => [
            'label' => __('analytics/subscribers_overview.products.'.$key),
            'value' => $value === null ? null : ($value === 0 ? '0' : $sign.ChartFormat::value($value)),
        ];

        $additions = $u[MovementLog::NEW] + $u[MovementLog::RESUMED] + $u[MovementLog::REACTIVATED];
        $reductions = $u[MovementLog::CANCELLED] + $u[MovementLog::PAUSED] + $u[MovementLog::EXPIRED];
        $net = $additions - $reductions;

        return [
            'additions' => [
                ['label' => __('analytics/subscribers_overview.activity.additions'), 'value' => ChartFormat::signed($additions), 'kind' => 'group', 'tone' => 'good'],
                $row('checkout', $u[MovementLog::NEW], '+'),
                $row('upsells', null, '+'),
                $row('quantity_increased', null, '+'),
                $row('reactivated', $u[MovementLog::REACTIVATED], '+'),
                $row('resumed', $u[MovementLog::RESUMED], '+'),
            ],
            'reductions' => [
                ['label' => __('analytics/subscribers_overview.activity.reductions'), 'value' => ChartFormat::signed(-$reductions), 'kind' => 'group', 'tone' => 'bad'],
                $row('cancelled', $u[MovementLog::CANCELLED], '−'),
                $row('paused', $u[MovementLog::PAUSED], '−'),
                $row('expired', $u[MovementLog::EXPIRED], '−'),
                $row('quantity_decreased', null, '−'),
            ],
            'net' => ChartFormat::signed($net),
            'net_tone' => $net > 0 ? 'good' : ($net < 0 ? 'bad' : null),
            'units' => ChartFormat::value($q['kpis']['quantity']['value']),
        ];
    }

    /** @return array<string, mixed> */
    private function churn(array $q, Context $context): array
    {
        $c = $q['churn'];
        $p = $q['churn_previous'];
        $series = $q['series'][SubscribersOverviewQuery::CHART_CANCELLED_MRR];

        return [
            'rate' => $c['rate'] === null ? null : ChartFormat::value($c['rate'], ChartFormat::PERCENT),
            'rate_delta' => $p !== null ? Delta::points($c['rate'], $p['rate']) : null,
            'lost' => ChartFormat::value($c['lost']),
            'start_active' => ChartFormat::value($c['start_active']),
            'zero_day' => $c['zero_day_share'] === null ? null : ChartFormat::value($c['zero_day_share'], ChartFormat::PERCENT),
            'zero_day_count' => $c['zero_day'],
            'lost_count' => $c['lost'],
            'cancelled_mrr' => ChartFormat::money($c['cancelled_mrr']),
            'chart' => [
                'id' => SubscribersOverviewQuery::CHART_CANCELLED_MRR,
                'labels' => $series['labels'],
                'bars' => [[
                    'key' => 'cancelled_mrr',
                    'label' => __('analytics/subscribers_overview.churn.cancelled_mrr'),
                    'tone' => 's3',
                    'values' => $series['values'],
                ]],
                'grain' => SubscribersOverviewQuery::grain($context, SubscribersOverviewQuery::CHART_CANCELLED_MRR)->value,
                'grains' => self::grainOptions($context->period),
            ],
        ];
    }
}
