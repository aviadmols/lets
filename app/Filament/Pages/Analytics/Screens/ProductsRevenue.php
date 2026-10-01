<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Products\ProductsRevenueQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Products › Revenue (spec §4.2 — no board; drawn in the ChartGrammar.dc.html
 * vocabulary: KPI row, a trend line per product, stacked Recurring/Checkout
 * bars, the full table). ProductsRevenueQuery computes; this class shapes.
 *
 * Partial by nature: revenue is what LETS collected (payment_ledger, net of
 * refunds; Shopify contracts from their billing attempts). Checkout = a plan's
 * first charge collected here; Shopify takes a contract's checkout itself.
 */
final class ProductsRevenue extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'ChartGrammar.dc.html';

    public const VIEW = 'filament.pages.analytics.products.revenue';

    public const LANG = 'analytics/products_revenue';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    /** Series tones of the trend lines, in product order (rank 1 = accent). */
    public const LINE_TONES = ['s1', 's2', 's5', 's3', 's4'];

    /** Bars per order type: recurring in the accent, checkout in teal (both additions). */
    public const TYPE_TONES = ['recurring' => 's1', 'checkout' => 's2'];

    /** Products drawn on the bar charts; the rest fold into "Other". */
    public const MAX_PRODUCTS = 6;

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new ProductsRevenueQuery($context))->get();
        $compare = SubscribersOverview::compareCaption($context->period);

        return [
            'has_data' => $q['has_data'],
            'kpis' => $this->kpis($q, $compare),
            'trend' => $this->trend($q, $context),
            'orders' => $this->stacked($q, 'orders', ChartFormat::NUMBER),
            'revenue' => $this->stacked($q, 'revenue', ChartFormat::MONEY),
            'table' => $this->table($q),
        ];
    }

    public function export(Context $context): ?array
    {
        $table = $this->table((new ProductsRevenueQuery($context))->get());

        return [
            'headers' => array_column($table['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $table['rows']),
        ];
    }

    // === Shaping ===

    /** @return list<array<string, mixed>> */
    private function kpis(array $q, ?string $compare): array
    {
        $out = [];
        foreach ($q['kpis'] as $key => $k) {
            $out[] = [
                'label' => __(self::LANG.'.kpi.'.$key),
                'value' => $key === 'orders' ? ChartFormat::value($k['value']) : ChartFormat::money((float) $k['value']),
                'delta' => $k['delta'],
                'compare' => $compare,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function trend(array $q, Context $context): array
    {
        $names = array_column($q['products'], 'title', 'pk');
        $series = [];
        $i = 0;
        foreach ($q['trend']['series'] as $pk => $values) {
            $series[] = [
                'key' => 'p'.$i,
                'label' => ProductsOverview::productLabel(self::LANG, (string) $pk, $names[$pk] ?? null),
                'tone' => self::LINE_TONES[$i] ?? 's6',
                'values' => $values,
            ];
            $i++;
        }
        if ($q['trend']['other'] !== []) {
            $series[] = ['key' => 'other', 'label' => __('analytics.other'), 'tone' => 's6', 'values' => $q['trend']['other'], 'dashed' => true];
        }

        return [
            'id' => ProductsRevenueQuery::CHART_TREND,
            'labels' => $q['trend']['labels'],
            'series' => $series,
            'grain' => ProductsRevenueQuery::grain($context)->value,
            'grains' => SubscribersOverview::grainOptions($context->period),
        ];
    }

    /** Recurring/Checkout per product, stacked. @return array<string, mixed> */
    private function stacked(array $q, string $measure, string $format): array
    {
        $products = $q['products'];
        usort($products, static fn (array $a, array $b): int => $b[$measure] <=> $a[$measure]);
        $top = array_slice($products, 0, self::MAX_PRODUCTS);
        $rest = array_slice($products, self::MAX_PRODUCTS);

        $labels = array_map(static fn (array $p): string => ProductsOverview::productLabel(self::LANG, $p['pk'], $p['title']), $top);
        if ($rest !== []) {
            $labels[] = __('analytics.other');
        }
        $bars = [];
        foreach (self::TYPE_TONES as $type => $tone) {
            $field = $type.'_'.$measure;
            $values = array_column($top, $field);
            if ($rest !== []) {
                $values[] = array_sum(array_column($rest, $field));
            }
            $bars[] = ['key' => $type, 'label' => __(self::LANG.'.type.'.$type), 'tone' => $tone, 'values' => $values];
        }
        $totals = array_map(static fn (array $p) => $p[$measure], [...$top, ...($rest === [] ? [] : [['orders' => array_sum(array_column($rest, 'orders')), 'revenue' => array_sum(array_column($rest, 'revenue'))]])]);

        return [
            'id' => 'product_'.$measure,
            'labels' => $labels,
            'bars' => $bars,
            'format' => $format,
            'caps' => array_map(static fn ($v): string => ChartFormat::value($v, $format), $totals),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function table(array $q): array
    {
        $l = self::LANG.'.table.';

        return [
            'columns' => [
                ['key' => 'product', 'label' => __($l.'product'), 'strong' => true],
                ['key' => 'variant', 'label' => __($l.'variant')],
                ['key' => 'revenue', 'label' => __($l.'revenue'), 'numeric' => true],
                ['key' => 'checkout_orders', 'label' => __($l.'checkout_orders'), 'numeric' => true],
                ['key' => 'checkout_revenue', 'label' => __($l.'checkout_revenue'), 'numeric' => true],
                ['key' => 'recurring_orders', 'label' => __($l.'recurring_orders'), 'numeric' => true],
                ['key' => 'recurring_revenue', 'label' => __($l.'recurring_revenue'), 'numeric' => true],
                ['key' => 'units', 'label' => __($l.'units'), 'numeric' => true],
            ],
            'rows' => array_map(static fn (array $r): array => [
                'product' => ProductsOverview::productLabel(self::LANG, $r['pk'], $r['title']),
                'variant' => $r['variant'] ?? '—',
                'revenue' => ChartFormat::money($r['revenue']),
                'checkout_orders' => ChartFormat::value($r['checkout_orders']),
                'checkout_revenue' => ChartFormat::money($r['checkout_revenue']),
                'recurring_orders' => ChartFormat::value($r['recurring_orders']),
                'recurring_revenue' => ChartFormat::money($r['recurring_revenue']),
                // Units billed: one per PayPlus order; a contract line's own quantity.
                'units' => ChartFormat::value($r['units']),
            ], $q['table']),
        ];
    }
}
