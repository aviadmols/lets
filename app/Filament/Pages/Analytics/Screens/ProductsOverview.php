<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Products\ProductMovements;
use App\Domain\Analytics\Products\ProductSql;
use App\Domain\Analytics\Products\ProductsOverviewQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Products › Overview (sketch Products.dc.html; spec §4.1).
 * ProductsOverviewQuery computes; this class shapes; the partial draws.
 *
 * Honest gaps (docs/analytics/data-map.md §4): "Swapped / removed" counts
 * account-offer switches only (a line removed from a Shopify contract carries
 * no quantity). Revenue is the ledger's: Shopify contract renewals have no
 * checkout leg in LETS.
 */
final class ProductsOverview extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Products.dc.html';

    public const VIEW = 'filament.pages.analytics.products.overview';

    public const LANG = 'analytics/products_overview';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTION_ACQUISITION = 'acquisition';

    public const OPTIONS = [self::OPTION_ACQUISITION => ['revenue', 'quantity']];

    /** Current-status series of the acquisition chart, in legend order. */
    public const STATUS_TONES = [
        ProductMovements::STATUS_ACTIVE => 's1',
        ProductMovements::STATUS_CANCELLED => 's4',
        ProductMovements::STATUS_PAUSED => 's3',
        ProductMovements::STATUS_EXPIRED => 's6',
        ProductMovements::SWAPPED => 's5',
        ProductMovements::STATUS_LAPSED => 's2',
    ];

    public const SLICE_TONES = ['s1', 's2', 's3', 's5', 's4', 's6'];

    /** Products drawn on a chart; the rest fold into "Other". */
    public const MAX_PRODUCTS = 6;

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new ProductsOverviewQuery($context))->get();
        $compare = SubscribersOverview::compareCaption($context->period);
        $unit = $context->option(self::OPTION_ACQUISITION, self::OPTIONS[self::OPTION_ACQUISITION]);

        return [
            'has_data' => $q['has_data'],
            'kpis' => $this->kpis($q, $compare),
            'by_product' => $this->slices($q['products']),
            'active_subscribers' => ChartFormat::value($q['kpis']['subscribers']['value']),
            'acquisition' => $this->acquisition($q),
            'acquisition_revenue' => $this->acquisitionRevenue($q, $unit),
            'unit' => $unit,
            'units' => [
                'revenue' => __(self::LANG.'.acquisition_revenue.revenue'),
                'quantity' => __(self::LANG.'.acquisition_revenue.quantity'),
            ],
            'table' => $this->table($q),
        ];
    }

    public function export(Context $context): ?array
    {
        $table = $this->table((new ProductsOverviewQuery($context))->get());

        return [
            'headers' => array_column($table['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values(array_map(
                static fn ($cell) => is_array($cell) ? ($cell['text'] ?? '') : $cell, $r)), $table['rows']),
        ];
    }

    /** A product's display name: the catalog's, else a stand-in that says why. */
    public static function productLabel(string $lang, string $pk, ?string $title): string
    {
        if ($title !== null && trim($title) !== '') {
            return $title;
        }

        return $pk === ProductSql::NONE ? __($lang.'.no_product') : __($lang.'.unknown_product', ['id' => $pk]);
    }

    // === Shaping ===

    /** @return list<array<string, mixed>> */
    private function kpis(array $q, ?string $compare): array
    {
        $k = $q['kpis'];

        return [
            ['label' => __(self::LANG.'.kpi.subscribers'), 'value' => ChartFormat::value($k['subscribers']['value']), 'delta' => $k['subscribers']['delta'], 'compare' => $compare],
            ['label' => __(self::LANG.'.kpi.quantity'), 'value' => ChartFormat::value($k['quantity']['value']), 'delta' => $k['quantity']['delta'], 'compare' => $compare],
            ['label' => __(self::LANG.'.kpi.upsells'), 'value' => ChartFormat::money((float) $k['upsells']['value']), 'delta' => $k['upsells']['delta'], 'compare' => $compare],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function slices(array $products): array
    {
        $products = array_values(array_filter($products, static fn (array $p): bool => $p['subscribers'] > 0));
        $top = array_slice($products, 0, self::MAX_PRODUCTS - 1);
        $rest = array_slice($products, self::MAX_PRODUCTS - 1);
        $out = [];
        foreach ($top as $i => $p) {
            $out[] = ['label' => self::productLabel(self::LANG, $p['pk'], $p['title']), 'value' => $p['subscribers'], 'tone' => self::SLICE_TONES[$i] ?? 's6'];
        }
        if ($rest !== []) {
            $out[] = ['label' => __('analytics.other'), 'value' => array_sum(array_column($rest, 'subscribers')), 'tone' => 's6'];
        }

        return $out;
    }

    /** @return array{labels: list<string>, bars: list<array<string, mixed>>, total: int} */
    private function acquisition(array $q): array
    {
        $acquired = $q['acquired'];
        uasort($acquired, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));
        $names = array_column($q['products'], 'title', 'pk');

        $keys = array_keys($acquired);
        $top = array_slice($keys, 0, self::MAX_PRODUCTS);
        $labels = array_map(fn ($pk): string => self::productLabel(self::LANG, (string) $pk, $names[$pk] ?? null), $top);
        $other = array_slice($keys, self::MAX_PRODUCTS);
        if ($other !== []) {
            $labels[] = __('analytics.other');
        }

        $bars = [];
        foreach (self::STATUS_TONES as $status => $tone) {
            $values = array_map(static fn ($pk): int => (int) round($acquired[$pk][$status]), $top);
            if ($other !== []) {
                $values[] = (int) round(array_sum(array_map(static fn ($pk): float => $acquired[$pk][$status], $other)));
            }
            if ($status === ProductMovements::STATUS_LAPSED && array_sum($values) === 0) {
                continue; // the sketch has no lapsed series; show it only when it is real
            }
            $bars[] = ['key' => $status, 'label' => __(self::LANG.'.status.'.$status), 'tone' => $tone, 'values' => $values];
        }

        return [
            'labels' => $labels,
            'bars' => $bars,
            'total' => (int) round(array_sum(array_map('array_sum', $acquired))),
        ];
    }

    /** @return array<string, mixed> */
    private function acquisitionRevenue(array $q, string $unit): array
    {
        $checkout = $q['checkout'];
        $field = $unit === 'quantity' ? 'orders' : 'revenue';
        uasort($checkout, static fn (array $a, array $b): int => $b[$field] <=> $a[$field]);
        $names = array_column($q['products'], 'title', 'pk');
        $keys = array_keys($checkout);
        $top = array_slice($keys, 0, self::MAX_PRODUCTS);
        $other = array_slice($keys, self::MAX_PRODUCTS);

        $labels = array_map(fn ($pk): string => self::productLabel(self::LANG, (string) $pk, $names[$pk] ?? null), $top);
        $values = array_map(static fn ($pk) => $checkout[$pk][$field], $top);
        if ($other !== []) {
            $labels[] = __('analytics.other');
            $values[] = array_sum(array_map(static fn ($pk) => $checkout[$pk][$field], $other));
        }
        $format = $unit === 'quantity' ? ChartFormat::NUMBER : ChartFormat::MONEY;

        return [
            'id' => 'acquisition_'.$unit,
            'labels' => $labels,
            'bars' => [[
                'key' => $field,
                'label' => __(self::LANG.'.acquisition_revenue.'.$unit),
                'tone' => 's1',
                'values' => $values,
            ]],
            'format' => $format,
            'caps' => array_map(static fn ($v): string => ChartFormat::value($v, $format), $values),
            'total' => ChartFormat::money(array_sum(array_column($checkout, 'revenue'))),
            'orders' => ChartFormat::value(array_sum(array_column($checkout, 'orders'))),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, summary: ?string} */
    private function table(array $q): array
    {
        $l = self::LANG.'.table.';
        $rows = array_map(static fn (array $r): array => [
            'product' => self::productLabel(self::LANG, $r['pk'], $r['title']),
            'variant' => $r['variant'] ?? '—',
            'new' => ChartFormat::value($r['new']),
            'active' => ChartFormat::value($r['active']),
            'revenue' => ChartFormat::money($r['revenue']),
            'per_payer' => $r['per_payer'] === null ? '—' : ChartFormat::money($r['per_payer']),
            'cancelled' => ChartFormat::value($r['cancelled']),
            'paused' => ChartFormat::value($r['paused']),
            'expired' => ChartFormat::value($r['expired']),
            'swapped' => ChartFormat::value($r['swapped']),
        ], $q['table']);

        $sum = static fn (string $key): int => (int) array_sum(array_column($q['table'], $key));

        return [
            'columns' => [
                ['key' => 'product', 'label' => __($l.'product'), 'strong' => true],
                ['key' => 'variant', 'label' => __($l.'variant')],
                ['key' => 'new', 'label' => __($l.'new'), 'numeric' => true],
                ['key' => 'active', 'label' => __($l.'active'), 'numeric' => true],
                ['key' => 'revenue', 'label' => __($l.'revenue'), 'numeric' => true],
                ['key' => 'per_payer', 'label' => __($l.'per_payer'), 'numeric' => true],
                ['key' => 'cancelled', 'label' => __($l.'cancelled'), 'numeric' => true],
                ['key' => 'paused', 'label' => __($l.'paused'), 'numeric' => true],
                ['key' => 'expired', 'label' => __($l.'expired'), 'numeric' => true],
                ['key' => 'swapped', 'label' => __($l.'swapped'), 'numeric' => true],
            ],
            'rows' => $rows,
            'summary' => $rows === [] ? null : __($l.'summary', [
                'products' => count(array_unique(array_column($q['table'], 'pk'))),
                'new' => ChartFormat::value($sum('new')),
                'cancelled' => ChartFormat::value($sum('cancelled')),
                'paused' => ChartFormat::value($sum('paused')),
                'expired' => ChartFormat::value($sum('expired')),
                'revenue' => ChartFormat::money((float) array_sum(array_column($q['table'], 'revenue'))),
            ]),
        ];
    }
}
