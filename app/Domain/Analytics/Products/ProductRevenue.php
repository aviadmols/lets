<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\Sql;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Subscription revenue by product, per day, split CHECKOUT vs RECURRING.
 *
 * PayPlus rail — payment_ledger rows of recurring plans, settled (succeeded, or
 * refunded later), net of refunds. A plan's FIRST settled charge is its
 * checkout order; every later one is a recurring order (docs/analytics/
 * data-map.md §1.4). Upsell and account-area add-on charges are NOT
 * subscription revenue (they belong to Upsells) and are excluded.
 *
 * Shopify rail — succeeded subscription_billing_attempts, priced at the
 * contract's amount and split across its lines by units. Shopify takes the
 * contract's first (checkout) order itself, so this rail only ever shows
 * recurring revenue.
 *
 * Aggregated in SQL by day × product × variant × order type; contracts are
 * fetched per billing attempt in the window (proportional to activity).
 */
final class ProductRevenue
{
    // === CONSTANTS ===
    /** Ledger statuses whose money was collected (a refund is netted, not dropped). */
    public const SETTLED = [PaymentLedger::STATUS_SUCCEEDED, PaymentLedger::STATUS_REFUNDED];

    /** Charges that are not subscription revenue. */
    public const NOT_SUBSCRIPTION = [PaymentLedger::CONTEXT_UPSELL, PaymentLedger::CONTEXT_ACCOUNT_OFFER];

    public function __construct(private readonly Filters $filters) {}

    /**
     * @return array{
     *   lines: list<array{day: string, pk: string, vk: string, title: ?string, checkout: bool, orders: int, units: int, revenue: float}>,
     *   orders: int,
     *   payers: array<string, int>,
     *   payers_by_variant: array<string, int>
     * }
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->ledgerRows($from, $to);

        $lines = DB::query()->fromSub($rows, 'r')
            ->selectRaw('d, pk, vk, co, MAX(title) as title, COUNT(*) as orders, COALESCE(SUM(net), 0) as revenue')
            ->groupBy('d', 'pk', 'vk', 'co')
            ->get()
            ->map(static fn ($r): array => [
                'day' => (string) $r->d,
                'pk' => (string) $r->pk,
                'vk' => (string) $r->vk,
                'title' => $r->title !== null ? (string) $r->title : null,
                'checkout' => (int) $r->co === 1,
                'orders' => (int) $r->orders,
                'units' => (int) $r->orders, // a PayPlus plan renews one unit per order
                'revenue' => round((float) $r->revenue, 2),
            ])->all();

        $payers = DB::query()->fromSub($this->ledgerRows($from, $to), 'r')
            ->selectRaw('pk, COUNT(DISTINCT k) as n')->groupBy('pk')->pluck('n', 'pk')
            ->map(static fn ($n): int => (int) $n)->all();
        $payersByVariant = DB::query()->fromSub($this->ledgerRows($from, $to), 'r')
            ->selectRaw("pk || '|' || vk as pv, COUNT(DISTINCT k) as n")->groupByRaw("pk || '|' || vk")->pluck('n', 'pv')
            ->map(static fn ($n): int => (int) $n)->all();

        // Orders, not lines: a contract renewal with two lines is ONE order.
        $orders = (int) array_sum(array_column($lines, 'orders'));
        $attempts = [];
        if ($this->filters->includesContracts()) {
            foreach ($this->contractRows($from, $to) as $row) {
                $attempts[$row['attempt']] = true;
                $lines[] = $row['line'];
                $payers[$row['line']['pk']] = ($payers[$row['line']['pk']] ?? 0) + $row['payer'];
                $pv = $row['line']['pk'].'|'.$row['line']['vk'];
                $payersByVariant[$pv] = ($payersByVariant[$pv] ?? 0) + $row['payer'];
            }
        }

        return ['lines' => $lines, 'orders' => $orders + count($attempts), 'payers' => $payers, 'payers_by_variant' => $payersByVariant];
    }

    public function forPeriod(Period $period): array
    {
        return $this->between($period->start(), $period->end());
    }

    /** One row per settled ledger charge: d, pk, vk, title, co (1 = checkout), net, k. */
    private function ledgerRows(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        // Correlated to the outer (tenant-scoped) row and pinned to its shop_id.
        $earlier = DB::table('payment_ledger as p2')
            ->selectRaw('1')
            ->whereColumn('p2.shop_id', 'payment_ledger.shop_id')
            ->whereColumn('p2.plan_id', 'payment_ledger.plan_id')
            ->whereIn('p2.status', self::SETTLED)
            ->whereNotIn('p2.charge_context', self::NOT_SUBSCRIPTION)
            ->whereColumn('p2.id', '<', 'payment_ledger.id');

        $query = PaymentLedger::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'payment_ledger.plan_id')
                    ->on('installment_plans.shop_id', '=', 'payment_ledger.shop_id');
            })
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->whereIn('payment_ledger.status', self::SETTLED)
            ->whereNotIn('payment_ledger.charge_context', self::NOT_SUBSCRIPTION)
            ->whereBetween('payment_ledger.created_at', [$from, $to]);

        $exists = 'EXISTS ('.$earlier->toSql().')';

        return $this->filters->applyToPlans($query)
            ->selectRaw(implode(', ', [
                Sql::day('payment_ledger.created_at').' as d',
                ProductSql::planProductKey().' as pk',
                ProductSql::planVariantKey().' as vk',
                ProductSql::planTitle().' as title',
                "(CASE WHEN {$exists} THEN 0 ELSE 1 END) as co",
                '(COALESCE(payment_ledger.amount, 0) - COALESCE(payment_ledger.refunded_amount, 0)) as net',
                Sql::planCustomerKey().' as k',
            ]), $earlier->getBindings())
            ->toBase();
    }

    /** @return list<array{line: array<string, mixed>, payer: int, attempt: int}> */
    private function contractRows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $at = 'COALESCE(subscription_billing_attempts.resolved_at, subscription_billing_attempts.created_at)';
        $rows = SubscriptionBillingAttempt::query()
            ->join('subscription_contracts', function ($join): void {
                $join->on('subscription_contracts.id', '=', 'subscription_billing_attempts.subscription_contract_id')
                    ->on('subscription_contracts.shop_id', '=', 'subscription_billing_attempts.shop_id');
            })
            ->where('subscription_billing_attempts.status', SubscriptionBillingAttempt::STATUS_SUCCEEDED)
            ->whereRaw("{$at} >= ? AND {$at} <= ?", [$from, $to]);

        $rows = $this->filters->applyToContracts($rows)
            ->selectRaw(implode(', ', [
                "{$at} as at",
                'subscription_billing_attempts.id as attempt_id',
                'subscription_contracts.id as contract_id',
                'subscription_contracts.amount as amount',
                'subscription_contracts.lines as lines',
            ]))->toBase()->get();

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $lines = json_decode((string) $r->lines, true);
            $day = CarbonImmutable::parse((string) $r->at)->format('Y-m-d');
            foreach (ProductAttribution::contractLines(is_array($lines) ? $lines : []) as $line) {
                $payerKey = $r->contract_id.'|'.$line['pk'];
                $out[] = [
                    'line' => [
                        'day' => $day,
                        'pk' => $line['pk'],
                        'vk' => $line['vk'],
                        'title' => $line['title'],
                        'checkout' => false,
                        'orders' => 1,
                        'units' => (int) $line['qty'],
                        'revenue' => round((float) $r->amount * $line['share'], 2),
                    ],
                    'payer' => isset($seen[$payerKey]) ? 0 : 1,
                    'attempt' => (int) $r->attempt_id,
                ];
                $seen[$payerKey] = true;
            }
        }

        return $out;
    }
}
