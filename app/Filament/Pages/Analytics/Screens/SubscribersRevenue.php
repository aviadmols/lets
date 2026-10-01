<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\RevenueQuery;
use App\Domain\Analytics\Subscribers\SubscriptionLedger;
use App\Domain\Analytics\Support\Delta;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Subscribers › Revenue (sketch Revenue.dc.html; spec §1.4).
 * RevenueQuery computes; this class shapes; the partial draws.
 *
 * Honest gaps (docs/analytics/data-map.md §1.4): the subscriber vs
 * non-subscriber split, overall store revenue and the subscription revenue
 * SHARE all need the store's non-subscription orders — not ingested, so they
 * render the not-tracked state. Subscription revenue, recurring vs checkout,
 * orders and AOV are real.
 */
final class SubscribersRevenue extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Revenue.dc.html';

    public const VIEW = 'filament.pages.analytics.subscribers.revenue';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const LANG = 'analytics/subscribers_revenue.';

    /** Series tones: recurring = accent, checkout = amber (as the sketch). */
    public const TONES = [
        SubscriptionLedger::KIND_RECURRING => 's1',
        SubscriptionLedger::KIND_CHECKOUT => 's3',
    ];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new RevenueQuery($context))->get();
        $t = $q['totals'];
        $all = $t['all']['revenue'];
        $recurring = SubscriptionLedger::KIND_RECURRING;
        $checkout = SubscriptionLedger::KIND_CHECKOUT;

        $share = static fn (string $kind): string => ChartFormat::value(Delta::share($t[$kind]['revenue'], $all), ChartFormat::PERCENT);
        $aov = static fn (array $kind): ?float => $kind['orders'] > 0 ? round($kind['revenue'] / $kind['orders'], 2) : null;
        $aovText = static fn (array $kind) => $aov($kind) === null ? '—' : ChartFormat::money($aov($kind));

        return [
            'has_data' => $q['has_data'],
            'subscription_revenue' => ChartFormat::money($all),
            'subscription_delta' => RevenueQuery::delta($q, 'all'),
            'compare' => SubscribersOverview::compareCaption($context->period),
            'split' => [
                ['label' => __(self::LANG.'split.total'), 'value' => ChartFormat::money($all), 'delta' => RevenueQuery::delta($q, 'all')],
                ['label' => __(self::LANG.'split.recurring', ['share' => $share($recurring)]), 'value' => ChartFormat::money($t[$recurring]['revenue']), 'delta' => RevenueQuery::delta($q, $recurring)],
                ['label' => __(self::LANG.'split.checkout', ['share' => $share($checkout)]), 'value' => ChartFormat::money($t[$checkout]['revenue']), 'delta' => RevenueQuery::delta($q, $checkout)],
            ],
            'grain' => RevenueQuery::grain($context)->value,
            'grains' => SubscribersOverview::grainOptions($context->period),
            'chart_id' => RevenueQuery::CHART_ID,
            'charts' => [
                [
                    'id' => 'revenue_orders',
                    'title' => __(self::LANG.'chart.orders'),
                    'value' => __(self::LANG.'chart.vs', ['a' => ChartFormat::value($t[$recurring]['orders']), 'b' => ChartFormat::value($t[$checkout]['orders'])]),
                    'format' => ChartFormat::NUMBER,
                    'series' => $this->series($q['series']['orders']),
                ],
                [
                    'id' => 'revenue_money',
                    'title' => __(self::LANG.'chart.revenue'),
                    'value' => __(self::LANG.'chart.vs', ['a' => ChartFormat::money($t[$recurring]['revenue']), 'b' => ChartFormat::money($t[$checkout]['revenue'])]),
                    'format' => ChartFormat::MONEY,
                    'series' => $this->series($q['series']['revenue']),
                ],
                [
                    'id' => 'revenue_aov',
                    'title' => __(self::LANG.'chart.aov'),
                    'value' => __(self::LANG.'chart.vs', ['a' => $aovText($t[$recurring]), 'b' => $aovText($t[$checkout])]),
                    'format' => ChartFormat::MONEY,
                    'series' => $this->series($this->aovSeries($q['series'])),
                ],
            ],
            'labels' => $q['series']['labels'],
            'legend' => array_map(static fn (string $kind): array => [
                'label' => __(self::LANG.'series.'.$kind), 'tone' => self::TONES[$kind], 'shape' => 'line',
            ], RevenueQuery::KINDS),
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new RevenueQuery($context))->get();
        $rows = [];
        foreach ($q['series']['labels'] as $i => $label) {
            $row = [$label];
            foreach (RevenueQuery::KINDS as $kind) {
                $row[] = (int) $q['series']['orders'][$kind][$i];
                $row[] = $q['series']['revenue'][$kind][$i];
            }
            $rows[] = $row;
        }

        return [
            'headers' => [
                __('analytics.table.period'),
                __(self::LANG.'series.recurring').' · '.__(self::LANG.'chart.orders'),
                __(self::LANG.'series.recurring').' · '.__(self::LANG.'chart.revenue'),
                __(self::LANG.'series.checkout').' · '.__(self::LANG.'chart.orders'),
                __(self::LANG.'series.checkout').' · '.__(self::LANG.'chart.revenue'),
            ],
            'rows' => $rows,
        ];
    }

    /** @param array<string, list<float|null>> $byKind @return list<array<string, mixed>> */
    private function series(array $byKind): array
    {
        return array_map(static fn (string $kind): array => [
            'key' => $kind,
            'label' => __(self::LANG.'series.'.$kind),
            'tone' => self::TONES[$kind],
            'values' => $byKind[$kind],
        ], RevenueQuery::KINDS);
    }

    /** AOV per bucket and kind; a bucket with no orders has no AOV (a gap, never a zero). */
    private function aovSeries(array $series): array
    {
        $out = [];
        foreach (RevenueQuery::KINDS as $kind) {
            $out[$kind] = array_map(
                static fn (float $orders, float $revenue): ?float => $orders > 0 ? round($revenue / $orders, 2) : null,
                $series['orders'][$kind],
                $series['revenue'][$kind],
            );
        }

        return $out;
    }
}
