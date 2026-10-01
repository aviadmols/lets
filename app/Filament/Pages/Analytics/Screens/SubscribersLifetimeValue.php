<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\LifetimeValueQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Subscribers › Lifetime value (sketch LifetimeValue.dc.html; spec §1.5).
 * LifetimeValueQuery computes; this class shapes; the partial draws.
 *
 * Honest partial (docs/analytics/data-map.md §1.5): aggregate SUBSCRIBER LTV,
 * its customers, orders and revenue, and its trend are real. Non-subscriber
 * LTV and the segments by the order number a customer subscribed at need the
 * customer's whole order history — not ingested — so the segment chart and
 * the distribution donut render the not-tracked state and the table shows
 * the non-subscriber row as dashes.
 */
final class SubscribersLifetimeValue extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'LifetimeValue.dc.html';

    public const VIEW = 'filament.pages.analytics.subscribers.lifetime_value';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const LANG = 'analytics/subscribers_lifetime_value.';

    public const NOT_TRACKED = '—';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new LifetimeValueQuery($context))->get();
        $now = $q['now'];

        return [
            'has_data' => $q['has_data'],
            'ltv' => $now['ltv'] === null ? null : ChartFormat::money($now['ltv']),
            'ltv_delta' => $q['ltv_delta'],
            'customers' => ChartFormat::value($now['customers']),
            'customer_count' => $now['customers'],
            'compare' => SubscribersOverview::compareCaption($context->period),
            'as_of' => $context->period->end()->locale(app()->getLocale())->translatedFormat('j M Y'),
            'table' => $this->table($now),
            'trend' => [
                'id' => LifetimeValueQuery::CHART_ID,
                'labels' => $q['series']['labels'],
                'series' => [[
                    'key' => 'subscribers',
                    'label' => __(self::LANG.'segment.subscribers'),
                    'tone' => 's1',
                    'values' => $q['series']['values'],
                ]],
                'grain' => LifetimeValueQuery::grain($context)->value,
                'grains' => SubscribersOverview::grainOptions($context->period),
            ],
        ];
    }

    public function export(Context $context): ?array
    {
        $table = $this->table((new LifetimeValueQuery($context))->get()['now']);

        return [
            'headers' => array_column($table['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $table['rows']),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function table(array $now): array
    {
        $columns = [
            ['key' => 'segment', 'label' => __(self::LANG.'table.segment'), 'strong' => true],
            ['key' => 'customers', 'label' => __(self::LANG.'table.customers'), 'numeric' => true],
            ['key' => 'orders', 'label' => __(self::LANG.'table.orders'), 'numeric' => true],
            ['key' => 'revenue', 'label' => __(self::LANG.'table.revenue'), 'numeric' => true],
            ['key' => 'per_customer', 'label' => __(self::LANG.'table.per_customer'), 'numeric' => true],
            ['key' => 'ltv', 'label' => __(self::LANG.'table.ltv'), 'numeric' => true],
        ];

        if ($now['customers'] === 0) {
            return ['columns' => $columns, 'rows' => []];
        }

        return [
            'columns' => $columns,
            'rows' => [
                [
                    'segment' => __(self::LANG.'segment.subscribers'),
                    'customers' => ChartFormat::value($now['customers']),
                    'orders' => ChartFormat::value($now['orders']),
                    'revenue' => ChartFormat::money($now['revenue']),
                    'per_customer' => ChartFormat::value($now['orders_per_customer']),
                    'ltv' => ChartFormat::money((float) $now['ltv']),
                ],
                [
                    'segment' => __(self::LANG.'segment.non_subscribers'),
                    'customers' => self::NOT_TRACKED,
                    'orders' => self::NOT_TRACKED,
                    'revenue' => self::NOT_TRACKED,
                    'per_customer' => self::NOT_TRACKED,
                    'ltv' => self::NOT_TRACKED,
                ],
            ],
        ];
    }
}
