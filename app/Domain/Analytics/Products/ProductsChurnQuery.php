<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Sql;
use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Models\ActivityEvent;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;

/**
 * Products › Churn & retention — the numbers (spec §4.3).
 *
 *   churn per product — CANCELLED movements (voluntary, and active → failed /
 *                       awaiting_payment = involuntary) attributed to products;
 *                       an account-offer SWAP is not churn and is left out.
 *   reasons           — the cancel event's details.reason: free text the
 *                       merchant typed, or a channel marker; empty = "no reason";
 *                       an involuntary lapse = "payment failed". Aggregated in SQL.
 *   cancellation rate — units cancelled in the period ÷ the product's units at
 *                       the period's start (walked back from today's book).
 *   Cancellation attempts / saves are NOT tracked — LETS has no cancellation
 *   flow — so the screen renders those as not tracked, never zero.
 */
final class ProductsChurnQuery
{
    // === CONSTANTS ===
    public const CHART_TREND = 'product_churn_trend';

    public const MEASURE_MRR = 'mrr';

    public const MEASURE_QUANTITY = 'quantity';

    public const MEASURES = [self::MEASURE_MRR, self::MEASURE_QUANTITY];

    public const TREND_PRODUCTS = 6;

    /** Reason buckets that are not free text. */
    public const REASON_NONE = '__none';

    public const REASON_PAYMENT_FAILED = '__payment_failed';

    /** A cancel reason written by an account-offer switch (a swap, not churn). */
    public const SWAP_REASON_PREFIX = AccountOfferAcceptService::REASON_REPLACED.':';

    public const CACHE_REASONS = 'products.churn_reasons';

    public function __construct(private readonly Context $context, private readonly string $measure = self::MEASURE_MRR) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_TREND, Granularity::WEEKLY);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $period = $this->context->period;
        $data = new ProductData($this->context);
        $book = $data->book();
        $movements = $data->movements();

        $series = $movements->churnSeries($period, self::grain($this->context), $this->measure);
        $totals = $movements->totals($period);
        $byVariant = $movements->totals($period, true);

        $hints = $movements->titles();
        foreach ($book['by_product'] as $pk => $row) {
            $hints[$pk] = $row['title'] ?? ($hints[$pk] ?? null);
        }
        $names = $data->names($hints);

        // Trend: the products that lost the most, the rest as "other".
        $lost = [];
        foreach ($series['products'] as $pk => $values) {
            $lost[$pk] = array_sum($values);
        }
        arsort($lost);
        $top = array_slice(array_keys($lost), 0, self::TREND_PRODUCTS);
        $trend = [];
        $other = [];
        foreach ($series['products'] as $pk => $values) {
            if (in_array($pk, $top, true)) {
                $trend[(string) $pk] = array_map(static fn (float $v): float => round($v, 2), $values);
            } else {
                foreach ($values as $i => $v) {
                    $other[$i] = round(($other[$i] ?? 0.0) + $v, 2);
                }
            }
        }
        uksort($trend, static fn ($a, $b): int => $lost[$b] <=> $lost[$a]);

        // Table: product × variant.
        $nowUnits = [];
        foreach ($book['by_variant'] as $v) {
            $nowUnits[$v['pk'].'|'.$v['vk']] = $v['qty'];
        }
        $startUnits = $movements->unitsAt($nowUnits, $period->start()->subSecond()->format('Y-m-d H:i:s'), true);
        $keys = array_unique([...array_keys($nowUnits), ...array_keys($byVariant)]);
        $variantNames = ProductAttribution::variantNames(array_map(static fn (string $k): string => (string) explode('|', $k, 2)[1], array_map('strval', $keys)));
        $table = [];
        foreach ($keys as $key) {
            [$pk, $vk] = array_pad(explode('|', (string) $key, 2), 2, '');
            $cancelled = (int) round($byVariant[$key][MovementLog::CANCELLED]['qty'] ?? 0);
            $start = (int) round($startUnits[$key] ?? 0);
            $table[] = [
                'pk' => $pk,
                'vk' => $vk,
                'title' => $names[$pk] ?? null,
                'variant' => $vk !== '' ? ($variantNames[$vk] ?? null) : null,
                'subscribed' => (int) ($nowUnits[$key] ?? 0),
                'start' => $start,
                'cancelled' => $cancelled,
                'cancelled_mrr' => round($byVariant[$key][MovementLog::CANCELLED]['mrr'] ?? 0.0, 2),
                'rate' => $start > 0 ? round($cancelled / $start * 100, 1) : null,
            ];
        }
        usort($table, static fn (array $a, array $b): int => [$b['subscribed'], $b['cancelled']] <=> [$a['subscribed'], $a['cancelled']]);

        $churned = ['mrr' => 0.0, 'qty' => 0.0];
        foreach ($totals as $types) {
            $churned['mrr'] += $types[MovementLog::CANCELLED]['mrr'] ?? 0.0;
            $churned['qty'] += $types[MovementLog::CANCELLED]['qty'] ?? 0.0;
        }

        return [
            'trend' => ['labels' => $series['labels'], 'products' => $trend, 'other' => $other],
            'names' => $names,
            'churned' => ['mrr' => round($churned['mrr'], 2), 'qty' => (int) round($churned['qty'])],
            'top_churned' => round(array_sum(array_map('array_sum', $trend)), 2),
            'reasons' => $this->reasons(),
            'table' => $table,
            'has_data' => $book['by_product'] !== [] || $table !== [],
        ];
    }

    /**
     * Churned units and MRR per reason in the period, both rails.
     *
     * @return list<array{reason: string, qty: int, mrr: float}>
     */
    public function reasons(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_REASONS, $period, $filters, function () use ($period, $filters): array {
            $from = Sql::jsonText('activity_events.details', 'from');
            $to = Sql::jsonText('activity_events.details', 'to');
            $reason = 'TRIM(CAST('.Sql::jsonText('activity_events.details', 'reason').' AS TEXT))';
            $cancelled = PlanStatus::CANCELLED->value;
            $lapsed = "'".implode("','", MovementLog::LAPSED)."'";
            $bucket = "(CASE WHEN {$to} <> '{$cancelled}' THEN '".self::REASON_PAYMENT_FAILED."'"
                ." ELSE COALESCE(NULLIF({$reason}, ''), '".self::REASON_NONE."') END)";

            $plans = $filters->applyToPlans(ActivityEvent::query()
                ->join('installment_plans', function ($join): void {
                    $join->on('installment_plans.id', '=', 'activity_events.plan_id')
                        ->on('installment_plans.shop_id', '=', 'activity_events.shop_id');
                })
                ->where('activity_events.kind', Timeline::KIND_STATUS_CHANGED)
                ->whereNull('activity_events.payment_id')
                ->whereBetween('activity_events.created_at', [$period->start(), $period->end()])
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->whereRaw("{$from} = ?", [ActiveBook::PLAN_ACTIVE[0]])
                ->whereRaw("({$to} = '{$cancelled}' OR {$to} IN ({$lapsed}))")
                ->whereRaw("COALESCE({$reason}, '') NOT LIKE ?", [self::SWAP_REASON_PREFIX.'%'])
            )->selectRaw("{$bucket} as r, COUNT(*) as qty, COALESCE(SUM(".Sql::planMrr().'), 0) as mrr')
                ->groupByRaw($bucket)
                ->toBase()->get();

            $out = [];
            foreach ($plans as $r) {
                $out[(string) $r->r] = ['reason' => (string) $r->r, 'qty' => (int) $r->qty, 'mrr' => round((float) $r->mrr, 2)];
            }

            if ($filters->includesContracts()) {
                $gid = Sql::jsonText('activity_events.details', 'contract_gid');
                $cBucket = "COALESCE(NULLIF({$reason}, ''), '".self::REASON_NONE."')";
                $contracts = $filters->applyToContracts(ActivityEvent::query()
                    ->join('subscription_contracts', function ($join) use ($gid): void {
                        $join->on('subscription_contracts.shop_id', '=', 'activity_events.shop_id')
                            ->whereRaw("subscription_contracts.shopify_gid = {$gid}");
                    })
                    ->where('activity_events.kind', ContractActionService::KIND_CANCELLED)
                    ->whereBetween('activity_events.created_at', [$period->start(), $period->end()])
                )->selectRaw("{$cBucket} as r, COALESCE(SUM(".Sql::contractQuantity().'), 0) as qty, COALESCE(SUM('.Sql::contractMrr().'), 0) as mrr')
                    ->groupByRaw($cBucket)
                    ->toBase()->get();
                foreach ($contracts as $r) {
                    $cell = $out[(string) $r->r] ?? ['reason' => (string) $r->r, 'qty' => 0, 'mrr' => 0.0];
                    $cell['qty'] += (int) $r->qty;
                    $cell['mrr'] = round($cell['mrr'] + (float) $r->mrr, 2);
                    $out[(string) $r->r] = $cell;
                }
            }

            $out = array_values($out);
            usort($out, static fn (array $a, array $b): int => [$b['mrr'], $b['qty']] <=> [$a['mrr'], $a['qty']]);

            return $out;
        });
    }
}
