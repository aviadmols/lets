<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Subscribers › Lifetime value — the numbers (spec §1.5, data-map §1.5).
 *
 * SUBSCRIBER LTV at a moment T = subscription revenue realised up to T (net of
 * refunds; SubscriptionLedger) ÷ the people who had become subscribers by T.
 * A person (Sql::planCustomerKey / contractCustomerKey — one human on both
 * rails is one person) "became a subscriber" when their first subscription was
 * created; a plan counts once it went live: any status past pre-active, or a
 * cancelled plan that was ever charged. Every Shopify contract counts.
 *
 * NOT TRACKED: non-subscriber LTV and the segments by the order number a
 * customer subscribed at — both need the customer's full order history.
 *
 * SQL: two GROUP BY day aggregates (first-subscription day per person, and
 * revenue per day) with everything before the earliest window folded into one
 * "before" row; the walk to each bucket end is PHP over those day totals.
 */
final class LifetimeValueQuery
{
    // === CONSTANTS ===
    public const CACHE_DAYS = 'lifetime_value.days';

    public const CHART_ID = 'ltv_trend';

    public const BEFORE = 'before';

    /** Plan statuses that prove a subscription went live. */
    public const LIVE = [
        PlanStatus::ACTIVE->value,
        PlanStatus::PAUSED->value,
        PlanStatus::FAILED->value,
        PlanStatus::AWAITING_PAYMENT->value,
        PlanStatus::COMPLETED->value,
    ];

    public function __construct(private readonly Context $context) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_ID, Granularity::WEEKLY);
    }

    /**
     * @return array{
     *   now: array{customers: int, orders: int, revenue: float, ltv: ?float, orders_per_customer: ?float},
     *   previous: ?array{customers: int, orders: int, revenue: float, ltv: ?float, orders_per_customer: ?float},
     *   ltv_delta: ?float,
     *   series: array{labels: list<string>, values: list<?float>},
     *   has_data: bool
     * }
     */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $days = $this->days();

        $now = $this->at($days, $period->end());
        $previous = $compare ? $this->at($days, $compare->end()) : null;

        $buckets = self::grain($this->context)->buckets($period);

        return [
            'now' => $now,
            'previous' => $previous,
            'ltv_delta' => $previous ? Delta::percent($now['ltv'], $previous['ltv']) : null,
            'series' => [
                'labels' => array_column($buckets, 'label'),
                'values' => array_map(fn (array $b): ?float => $this->at($days, $b['end'])['ltv'], $buckets),
            ],
            'has_data' => $now['customers'] > 0,
        ];
    }

    /** @return array{customers: int, orders: int, revenue: float, ltv: ?float, orders_per_customer: ?float} */
    private function at(array $days, CarbonImmutable $t): array
    {
        $cut = $t->format(Granularity::DAY_KEY);
        $sum = static function (array $byDay, string $field) use ($cut): float {
            $total = 0.0;
            foreach ($byDay as $day => $row) {
                if ($day === self::BEFORE || $day <= $cut) {
                    $total += $row[$field];
                }
            }

            return $total;
        };

        $customers = (int) $sum($days['people'], 'n');
        $orders = (int) $sum($days['revenue'], 'orders');
        $revenue = round($sum($days['revenue'], 'revenue'), 2);

        return [
            'customers' => $customers,
            'orders' => $orders,
            'revenue' => $revenue,
            'ltv' => $customers > 0 ? round($revenue / $customers, 2) : null,
            'orders_per_customer' => $customers > 0 ? round($orders / $customers, 1) : null,
        ];
    }

    /** @return array{people: array<string, array{n: int}>, revenue: array<string, array{orders: int, revenue: float}>} */
    private function days(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_DAYS, $period, $filters, function () use ($period, $filters): array {
            $earliest = $period->earliest()->format('Y-m-d H:i:s');
            $end = $period->end();

            return [
                'people' => $this->people($filters, $earliest, $end),
                'revenue' => $this->revenue($filters, $earliest, $end),
            ];
        });
    }

    /** @return array<string, array{n: int}> first-subscription day ('before' folded) => people */
    private function people(Filters $filters, string $earliest, CarbonImmutable $end): array
    {
        $realised = "'".implode("','", SubscriptionLedger::REALISED)."'";
        $contexts = "'".implode("','", SubscriptionLedger::CONTEXTS)."'";

        $plans = $filters->applyToPlans(
            InstallmentPlan::query()
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->where(fn ($q) => $q->whereIn('installment_plans.status', self::LIVE)
                    ->orWhereRaw('EXISTS (SELECT 1 FROM payment_ledger f WHERE f.shop_id = installment_plans.shop_id '
                        ."AND f.plan_id = installment_plans.id AND f.status IN ({$realised}) AND f.charge_context IN ({$contexts}))"))
        )->selectRaw(Sql::planCustomerKey().' as k, installment_plans.created_at as at')->toBase();

        if ($filters->includesContracts()) {
            $plans->unionAll($filters->applyToContracts(SubscriptionContract::query())
                ->selectRaw(Sql::contractCustomerKey().' as k, subscription_contracts.created_at as at')->toBase());
        }

        $firsts = DB::query()->fromSub($plans, 'u')->selectRaw('k, MIN(at) as first_at')->groupBy('k');
        $bucket = "CASE WHEN first_at < '{$earliest}' THEN '".self::BEFORE."' ELSE ".Sql::day('first_at').' END';

        $out = [];
        foreach (DB::query()->fromSub($firsts, 'p')->where('first_at', '<=', $end)
            ->selectRaw("{$bucket} as d, COUNT(*) as n")->groupByRaw($bucket)->get() as $r) {
            $out[(string) $r->d] = ['n' => (int) $r->n];
        }

        return $out;
    }

    /** @return array<string, array{orders: int, revenue: float}> day ('before' folded) => realised orders + money */
    private function revenue(Filters $filters, string $earliest, CarbonImmutable $end): array
    {
        $ledger = new SubscriptionLedger($filters);
        $out = [];
        $merge = static function (iterable $rows) use (&$out): void {
            foreach ($rows as $r) {
                $cur = $out[(string) $r->d] ?? ['orders' => 0, 'revenue' => 0.0];
                $out[(string) $r->d] = ['orders' => $cur['orders'] + (int) $r->orders, 'revenue' => $cur['revenue'] + (float) $r->revenue];
            }
        };

        $bucket = "CASE WHEN at < '{$earliest}' THEN '".self::BEFORE."' ELSE ".Sql::day('at').' END';

        $rows = $ledger->realisedPlanRows()
            ->where('payment_ledger.created_at', '<=', $end)
            ->selectRaw('payment_ledger.created_at as at, '.SubscriptionLedger::NET.' as net')->toBase();
        $merge(DB::query()->fromSub($rows, 'u')->selectRaw("{$bucket} as d, COUNT(*) as orders, COALESCE(SUM(net), 0) as revenue")->groupByRaw($bucket)->get());

        $attempts = $ledger->contractAttempts();
        if ($attempts !== null) {
            $at = SubscriptionLedger::attemptAt();
            $rows = $attempts->whereRaw("{$at} <= ?", [$end])
                ->selectRaw("{$at} as at, COALESCE(subscription_contracts.amount, 0) as net")->toBase();
            $merge(DB::query()->fromSub($rows, 'u')->selectRaw("{$bucket} as d, COUNT(*) as orders, COALESCE(SUM(net), 0) as revenue")->groupByRaw($bucket)->get());
        }

        return $out;
    }
}
