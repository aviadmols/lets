<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Subscribers\SubscribersOverviewQuery;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Upsells\UpsellsQuery;

/**
 * Products › Overview — the numbers (spec §4.1). Never formats or translates.
 *
 *   KPIs        — active subscribers + subscribed units (the SAME numbers as
 *                 Subscribers › Overview, walked back for the compare delta) and
 *                 one-time upsells billed in the period (UpsellsQuery::sold).
 *   by product  — today's active book per product (ProductBook).
 *   acquisition — subscriptions that arrived NEW in the period, per product ×
 *                 the status they hold today; their checkout revenue (each
 *                 plan's first settled charge, ProductRevenue).
 *   table       — per product × variant: new units, active units, subscription
 *                 revenue in the period, revenue per paying subscriber, and the
 *                 period's cancelled / paused / expired / swapped units.
 */
final class ProductsOverviewQuery
{
    // === CONSTANTS ===
    public const CACHE_REVENUE = 'products.revenue';

    public function __construct(private readonly Context $context) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $data = new ProductData($this->context);

        $subscribers = (new SubscribersOverviewQuery($this->context))->get()['kpis'];
        $upsells = new UpsellsQuery($this->context);
        $upsellNow = $upsells->sold($period)['revenue'];
        $upsellBefore = $compare ? $upsells->sold($compare)['revenue'] : null;

        $book = $data->book();
        $movements = $data->movements();
        $revenue = self::revenue($this->context);

        // Names: catalog first, then whatever title the rows carried.
        $hints = $movements->titles();
        foreach ($book['by_product'] as $pk => $row) {
            $hints[$pk] = $row['title'] ?? ($hints[$pk] ?? null);
        }
        foreach ($revenue['lines'] as $line) {
            $hints[$line['pk']] ??= $line['title'];
        }
        $names = $data->names($hints);

        // Per product × variant table.
        $variantNames = ProductAttribution::variantNames(array_column($book['by_variant'], 'vk'));
        $moves = $movements->totals($period, true);
        $table = [];
        $row = static function (string $pk, string $vk) use (&$table): void {
            $table[$pk.'|'.$vk] ??= ['pk' => $pk, 'vk' => $vk, 'new' => 0.0, 'active' => 0, 'active_subscribers' => 0, 'revenue' => 0.0, 'payers' => 0,
                'cancelled' => 0.0, 'paused' => 0.0, 'expired' => 0.0, 'swapped' => 0.0];
        };
        foreach ($book['by_variant'] as $v) {
            $row($v['pk'], $v['vk']);
            $table[$v['pk'].'|'.$v['vk']]['active'] = $v['qty'];
            $table[$v['pk'].'|'.$v['vk']]['active_subscribers'] = $v['subscribers'];
        }
        foreach ($revenue['lines'] as $line) {
            $row($line['pk'], $line['vk']);
            $table[$line['pk'].'|'.$line['vk']]['revenue'] += $line['revenue'];
        }
        foreach ($revenue['payers_by_variant'] as $pv => $n) {
            [$pk, $vk] = array_pad(explode('|', (string) $pv, 2), 2, '');
            $row($pk, $vk);
            $table[$pk.'|'.$vk]['payers'] = $n;
        }
        $map = [
            MovementLog::NEW => 'new',
            MovementLog::CANCELLED => 'cancelled',
            MovementLog::PAUSED => 'paused',
            MovementLog::EXPIRED => 'expired',
            ProductMovements::SWAPPED => 'swapped',
        ];
        foreach ($moves as $pv => $types) {
            [$pk, $vk] = array_pad(explode('|', (string) $pv, 2), 2, '');
            $row($pk, $vk);
            foreach ($map as $type => $column) {
                $table[$pk.'|'.$vk][$column] += $types[$type]['qty'] ?? 0.0;
            }
        }
        foreach ($table as &$t) {
            $t['title'] = $names[$t['pk']] ?? null;
            $t['variant'] = $t['vk'] !== '' ? ($variantNames[$t['vk']] ?? null) : null;
            $t['revenue'] = round($t['revenue'], 2);
            $t['per_payer'] = $t['payers'] > 0 ? round($t['revenue'] / $t['payers'], 2) : null;
            foreach (['new', 'cancelled', 'paused', 'expired', 'swapped'] as $c) {
                $t[$c] = (int) round($t[$c]);
            }
        }
        unset($t);
        $table = array_values($table);
        usort($table, static fn (array $a, array $b): int => [$b['active'], $b['revenue']] <=> [$a['active'], $a['revenue']]);

        // Per product: active subscribers, checkout revenue/orders, acquisitions by status.
        $products = [];
        foreach ($book['by_product'] as $pk => $p) {
            $products[$pk] = ['pk' => (string) $pk, 'title' => $names[$pk] ?? null, 'subscribers' => $p['subscribers'], 'qty' => $p['qty']];
        }
        $checkout = [];
        foreach ($revenue['lines'] as $line) {
            if ($line['checkout']) {
                $checkout[$line['pk']] ??= ['revenue' => 0.0, 'orders' => 0];
                $checkout[$line['pk']]['revenue'] = round($checkout[$line['pk']]['revenue'] + $line['revenue'], 2);
                $checkout[$line['pk']]['orders'] += $line['orders'];
            }
        }
        $acquired = $movements->acquiredByStatus($period);
        foreach (array_keys($checkout + $acquired) as $pk) {
            $products[$pk] ??= ['pk' => (string) $pk, 'title' => $names[$pk] ?? null, 'subscribers' => 0, 'qty' => 0];
        }
        uasort($products, static fn (array $a, array $b): int => $b['subscribers'] <=> $a['subscribers']);

        $kpi = static fn (float|int $value, float|int|null $previous): array => ['value' => $value, 'previous' => $previous, 'delta' => Delta::percent($value, $previous)];

        return [
            'kpis' => [
                'subscribers' => $subscribers['subscribers'],
                'quantity' => $subscribers['quantity'],
                'upsells' => $kpi($upsellNow, $upsellBefore),
            ],
            'products' => array_values($products),
            'acquired' => $acquired,
            'checkout' => $checkout,
            'table' => $table,
            'has_data' => $book['by_product'] !== [] || $table !== [],
        ];
    }

    /** The period's product revenue, cached (shared with Products › Revenue). */
    public static function revenue(Context $context, ?\App\Domain\Analytics\Period $window = null): array
    {
        $window ??= $context->period;
        $filters = $context->filters;

        return AnalyticsCache::remember(self::CACHE_REVENUE, $context->period, $filters,
            static fn (): array => (new ProductRevenue($filters))->forPeriod($window),
            $window->key());
    }
}
