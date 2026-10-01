<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Support\Delta;

/**
 * Products › Revenue — the numbers (spec §4.2). Subscription revenue per
 * product from ProductRevenue (ledger, net of refunds; checkout = a plan's
 * first settled charge, recurring = every later one; Shopify contracts are
 * recurring only). Never formats or translates.
 */
final class ProductsRevenueQuery
{
    // === CONSTANTS ===
    public const CHART_TREND = 'product_revenue_trend';

    /** Lines on the trend chart (the rest fold into "Other"). */
    public const TREND_PRODUCTS = 5;

    public function __construct(private readonly Context $context) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_TREND, Granularity::WEEKLY);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $now = ProductsOverviewQuery::revenue($this->context);
        $before = $compare ? ProductsOverviewQuery::revenue($this->context, $compare) : null;

        $totals = self::totals($now['lines'], $now['orders']);
        $previous = $before ? self::totals($before['lines'], $before['orders']) : null;
        $kpi = static fn (string $key) => [
            'value' => $totals[$key],
            'previous' => $previous[$key] ?? null,
            'delta' => Delta::percent($totals[$key], $previous[$key] ?? null),
        ];

        // Per product and per product × variant.
        $byProduct = [];
        $byVariant = [];
        $hints = [];
        $add = static function (array &$bucket, string $key, array $line, string $vk): void {
            $bucket[$key] ??= ['pk' => $line['pk'], 'vk' => $vk, 'checkout_orders' => 0, 'checkout_revenue' => 0.0, 'recurring_orders' => 0, 'recurring_revenue' => 0.0, 'units' => 0];
            $type = $line['checkout'] ? 'checkout' : 'recurring';
            $bucket[$key][$type.'_orders'] += $line['orders'];
            $bucket[$key]['units'] += $line['units'];
            $bucket[$key][$type.'_revenue'] = round($bucket[$key][$type.'_revenue'] + $line['revenue'], 2);
        };
        foreach ($now['lines'] as $line) {
            $hints[$line['pk']] ??= $line['title'];
            $add($byProduct, $line['pk'], $line, '');
            $add($byVariant, $line['pk'].'|'.$line['vk'], $line, $line['vk']);
        }
        $names = ProductAttribution::productNames($hints);
        $variantNames = ProductAttribution::variantNames(array_column($byVariant, 'vk'));
        $finish = static function (array $rows) use ($names, $variantNames): array {
            foreach ($rows as &$r) {
                $r['title'] = $names[$r['pk']] ?? null;
                $r['variant'] = $r['vk'] !== '' ? ($variantNames[$r['vk']] ?? null) : null;
                $r['revenue'] = round($r['checkout_revenue'] + $r['recurring_revenue'], 2);
                $r['orders'] = $r['checkout_orders'] + $r['recurring_orders'];
            }
            unset($r);
            $rows = array_values($rows);
            usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

            return $rows;
        };
        $byProduct = $finish($byProduct);

        return [
            'kpis' => [
                'revenue' => $kpi('revenue'),
                'checkout' => $kpi('checkout'),
                'recurring' => $kpi('recurring'),
                'orders' => $kpi('orders'),
            ],
            'trend' => $this->trend($now['lines'], array_slice(array_column($byProduct, 'pk'), 0, self::TREND_PRODUCTS)),
            'products' => $byProduct,
            'table' => $finish($byVariant),
            'has_data' => $now['lines'] !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{revenue: float, checkout: float, recurring: float, orders: int}
     */
    public static function totals(array $lines, int $orders): array
    {
        $out = ['revenue' => 0.0, 'checkout' => 0.0, 'recurring' => 0.0, 'orders' => $orders];
        foreach ($lines as $line) {
            $out['revenue'] += $line['revenue'];
            $out[$line['checkout'] ? 'checkout' : 'recurring'] += $line['revenue'];
        }

        return array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $out);
    }

    /**
     * Revenue per bucket for the top products, the rest as one "other" series.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $top
     * @return array{labels: list<string>, series: array<string, list<float>>, other: list<float>}
     */
    private function trend(array $lines, array $top): array
    {
        $period = $this->context->period;
        $grain = self::grain($this->context);
        $byDay = array_fill_keys($top, []);
        $other = [];
        foreach ($lines as $line) {
            if (in_array($line['pk'], $top, true)) {
                $byDay[$line['pk']][$line['day']] = ($byDay[$line['pk']][$line['day']] ?? 0.0) + $line['revenue'];
            } else {
                $other[$line['day']] = ($other[$line['day']] ?? 0.0) + $line['revenue'];
            }
        }
        $round = static fn (array $v): array => array_map(static fn (float $x): float => round($x, 2), $v);

        return [
            'labels' => array_column($grain->buckets($period), 'label'),
            'series' => array_map(static fn (array $d): array => $round($grain->rollUp($period, $d)), $byDay),
            'other' => $other === [] ? [] : $round($grain->rollUp($period, $other)),
        ];
    }
}
