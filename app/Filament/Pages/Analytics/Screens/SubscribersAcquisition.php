<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\AcquisitionQuery;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Support\Frequency;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Subscribers › Acquisition (sketch Acquisition.dc.html; spec §1.2).
 * AcquisitionQuery computes; this class shapes; the partial draws.
 *
 * Honest gaps (docs/analytics/data-map.md §1.2): non-subscribed customers, the
 * acquisition rate and acquisition by order number need the store's orders of
 * customers who never subscribed — not ingested, so those cards render the
 * not-tracked state. The rate-trend card shows the acquired COUNT instead,
 * and says so.
 */
final class SubscribersAcquisition extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Acquisition.dc.html';

    public const VIEW = 'filament.pages.analytics.subscribers.acquisition';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    /** Product bars shown per panel. */
    public const MAX_PRODUCTS = 8;

    public const LANG = 'analytics/subscribers_acquisition.';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new AcquisitionQuery($context))->get();
        $compare = SubscribersOverview::compareCaption($context->period);
        $acquired = $q['acquired']['value'];

        $products = array_slice($q['by_product'], 0, self::MAX_PRODUCTS);
        $productLabel = fn (array $p): string => $this->productLabel($p);

        return [
            'has_data' => $q['has_data'],
            'kpis' => [
                'acquired' => [
                    'value' => ChartFormat::value($acquired),
                    'delta' => $q['acquired']['delta'],
                    'compare' => $compare,
                    'caption' => __(self::LANG.'kpi.acquired_caption', ['n' => ChartFormat::value($acquired), 'additions' => ChartFormat::value($q['additions'])]),
                ],
                'zero_day' => [
                    'value' => ChartFormat::value($q['zero_day']['value']),
                    'delta' => $q['zero_day']['delta'],
                    'compare' => $compare,
                    'caption' => $q['zero_day']['share'] === null ? null
                        : __(self::LANG.'kpi.zero_day_caption', ['share' => ChartFormat::value($q['zero_day']['share'], ChartFormat::PERCENT)]),
                ],
            ],
            'trend' => [
                'id' => AcquisitionQuery::CHART_TREND,
                'labels' => $q['trend']['labels'],
                'series' => [[
                    'key' => 'acquired',
                    'label' => __(self::LANG.'trend.series'),
                    'tone' => 's1',
                    'values' => $q['trend']['values'],
                    'area' => true,
                ]],
                'grain' => AcquisitionQuery::grain($context)->value,
                'grains' => SubscribersOverview::grainOptions($context->period),
            ],
            'by_quantity' => array_map(static fn (array $p): array => [
                'label' => $productLabel($p), 'value' => $p['quantity'], 'tone' => 's1',
            ], $products),
            'by_revenue' => array_map(static fn (array $p): array => [
                'label' => $productLabel($p), 'value' => $p['revenue'], 'display' => ChartFormat::money($p['revenue']), 'tone' => 's2',
            ], self::sortBy($products, 'revenue')),
            'units' => ChartFormat::value(array_sum(array_column($q['by_product'], 'quantity'))),
            'revenue' => ChartFormat::money(array_sum(array_column($q['by_product'], 'revenue'))),
            'table' => $this->table($q),
        ];
    }

    public function export(Context $context): ?array
    {
        $table = $this->table((new AcquisitionQuery($context))->get());

        return [
            'headers' => array_column($table['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $table['rows']),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function table(array $q): array
    {
        return [
            'columns' => [
                ['key' => 'plan', 'label' => __(self::LANG.'table.plan'), 'strong' => true],
                ['key' => 'frequency', 'label' => __(self::LANG.'table.frequency')],
                ['key' => 'subscribers', 'label' => __(self::LANG.'table.subscribers'), 'numeric' => true],
                ['key' => 'mrr', 'label' => __(self::LANG.'table.mrr'), 'numeric' => true],
                ['key' => 'zero_day', 'label' => __(self::LANG.'table.zero_day'), 'numeric' => true],
            ],
            'rows' => array_map(fn (array $r): array => [
                'plan' => $this->planLabel($r['plan_key'], $r['plan']),
                'frequency' => Frequency::label($r['freq']),
                'subscribers' => ChartFormat::value($r['subscribers']),
                'mrr' => ChartFormat::money($r['mrr']),
                'zero_day' => ChartFormat::value($r['zero_day']),
            ], $q['plan_frequency']),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function sortBy(array $rows, string $field): array
    {
        usort($rows, static fn (array $a, array $b): int => $b[$field] <=> $a[$field]);

        return $rows;
    }

    private function productLabel(array $p): string
    {
        return match (true) {
            $p['key'] === AcquisitionQuery::PRODUCT_SHOPIFY => __('analytics.selling_plan.shopify'),
            $p['label'] !== null => (string) $p['label'],
            $p['key'] === ActiveBook::PLAN_NONE => __(self::LANG.'product.unknown'),
            default => __(self::LANG.'product.unnamed', ['id' => $p['key']]),
        };
    }

    private function planLabel(string $key, ?string $name): string
    {
        return match ($key) {
            ActiveBook::PLAN_NONE => __('analytics.selling_plan.none'),
            ActiveBook::PLAN_SHOPIFY => __('analytics.selling_plan.shopify'),
            default => trim((string) $name) !== '' ? (string) $name : __('analytics.selling_plan.unnamed', ['id' => $key]),
        };
    }
}
