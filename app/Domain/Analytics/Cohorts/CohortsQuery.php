<?php

namespace App\Domain\Analytics\Cohorts;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cohorts — the numbers (spec §2, data-map §2). Never formats or translates.
 *
 * A COHORT is the calendar month a member joined:
 *   subscription level — the month of the subscription's New movement (MovementLog);
 *   subscriber level   — the month of the person's FIRST subscription. A person
 *                        who already had a subscription before the window is not
 *                        a joiner of any month in it and is left out (checked in
 *                        SQL against plans/contracts created before the window).
 *
 * Month m of a member is measured from THEIR OWN join moment, so Month 0 is
 * 100% retained by definition: retention[m] = members active m months after
 * joining ÷ cohort size. "Active at t" is replayed from the member's own
 * movements (every movement after a join inside the window is in the log).
 * The newest cell of a cohort is read at "now" while its later joiners have
 * not reached the anniversary yet — the board's "partial latest month".
 *
 * Orders/revenue: succeeded (and later refunded) payment_ledger rows per plan,
 * net of refunds; Shopify-Payments contracts from succeeded billing attempts
 * at the contract amount. LIMITATION (shown on screen): a contract's checkout
 * order is Shopify's, not a billing attempt, so contracts start counting
 * orders at their first renewal.
 */
final class CohortsQuery
{
    // === CONSTANTS ===
    public const CACHE_KEY = 'cohorts.members';

    public const LEVEL_SUBSCRIBER = 'subscriber';

    public const LEVEL_SUBSCRIPTION = 'subscription';

    public const METRIC_RETENTION = 'retention';

    public const METRIC_ORDERS = 'orders';

    public const METRIC_AVG_ORDERS = 'avg_orders';

    public const METRIC_REVENUE = 'revenue';

    public const METRIC_LTV = 'ltv';

    public const METRICS = [self::METRIC_RETENTION, self::METRIC_ORDERS, self::METRIC_AVG_ORDERS, self::METRIC_REVENUE, self::METRIC_LTV];

    /** Cumulative metrics grow with m: their %-of-Month-0 reading means nothing, they show values. */
    public const CUMULATIVE = [self::METRIC_AVG_ORDERS, self::METRIC_LTV];

    /** The option values of the metric selector: "<level>_<metric>", first = default. */
    public const OPTIONS = [
        'subscriber_retention', 'subscriber_orders', 'subscriber_avg_orders', 'subscriber_revenue', 'subscriber_ltv',
        'subscription_retention', 'subscription_orders', 'subscription_avg_orders', 'subscription_revenue', 'subscription_ltv',
    ];

    /** Cohort window, in months (first = default) + year to date. */
    public const SPANS = ['12', '3', '6', '18', '24', '36', 'ytd'];

    /** Columns Month 0 … Month 12. */
    public const MAX_MONTH = 12;

    public const CHUNK = 900;

    /** Ledger statuses that are money received (refunds netted out by refunded_amount). */
    public const PAID = [LedgerStatus::SUCCEEDED->value, LedgerStatus::REFUNDED->value];

    public function __construct(private readonly Context $context) {}

    /** @return array{0: string, 1: string} [level, metric] of a selector option */
    public static function split(string $option): array
    {
        [$level, $metric] = explode('_', $option, 2) + [1 => self::METRIC_RETENTION];

        return [$level === self::LEVEL_SUBSCRIPTION ? self::LEVEL_SUBSCRIPTION : self::LEVEL_SUBSCRIBER,
            in_array($metric, self::METRICS, true) ? $metric : self::METRIC_RETENTION];
    }

    public static function windowStart(string $span, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today = ($today ?? CarbonImmutable::today())->startOfMonth();

        return $span === 'ytd' ? $today->startOfYear() : $today->subMonths(max(1, (int) $span) - 1);
    }

    /**
     * Oldest cohort first (the board's order).
     *
     * @return array{
     *   level: string, metric: string, columns: int,
     *   rows: list<array{month: string, size: int, cells: list<?float>, values: list<float|int>}>,
     *   has_data: bool, has_contracts: bool
     * }
     */
    public function get(): array
    {
        [$level, $metric] = self::split($this->context->option('metric', self::OPTIONS));
        $span = $this->context->option('span', self::SPANS);
        $since = self::windowStart($span);
        $members = $this->members($since);

        $now = CarbonImmutable::now();
        $today = CarbonImmutable::today();
        $set = $members[$level];
        $cohorts = [];
        foreach ($set as $m) {
            $cohorts[substr($m['joined'], 0, 7)][] = $m;
        }

        $rows = [];
        $maxSeen = 0;
        for ($month = $since; $month->lessThanOrEqualTo($today); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $list = $cohorts[$key] ?? [];
            $visible = min(self::MAX_MONTH, (int) floor($month->diffInMonths($today->startOfMonth(), true)));
            $values = $this->cohortValues($list, $metric, $visible, $now);
            $maxSeen = max($maxSeen, $visible);
            $rows[] = ['month' => $key, 'size' => count($list), 'values' => $values];
        }

        return [
            'level' => $level,
            'metric' => $metric,
            'columns' => $maxSeen + 1,
            'rows' => self::withCells($rows, $metric),
            'has_data' => $set !== [],
            'has_contracts' => $members['has_contracts'],
        ];
    }

    /**
     * The printed values of one cohort row, Month 0 … $visible.
     *
     * @param  list<array<string, mixed>>  $list
     * @return list<float|int>
     */
    private function cohortValues(array $list, string $metric, int $visible, CarbonImmutable $now): array
    {
        $size = count($list);
        $out = [];
        $cumulative = 0.0;
        for ($m = 0; $m <= $visible; $m++) {
            if ($size === 0) {
                $out[] = 0;

                continue;
            }
            $value = match (true) {
                // Month 0 IS the join: every member is in it by definition.
                $metric === self::METRIC_RETENTION && $m === 0 => $size,
                $metric === self::METRIC_RETENTION => count(array_filter($list, function (array $member) use ($m, $now): bool {
                    $joined = CarbonImmutable::parse($member['joined']);

                    // Read at the anniversary, or now if it has not come yet — never before the join.
                    return self::activeAt($member['moves'], $joined->addMonthsNoOverflow($m)->min($now)->max($joined));
                })),
                in_array($metric, [self::METRIC_ORDERS, self::METRIC_AVG_ORDERS], true) => array_sum(array_map(static fn (array $member): int => $member['orders'][$m] ?? 0, $list)),
                default => array_sum(array_map(static fn (array $member): float => $member['revenue'][$m] ?? 0.0, $list)),
            };
            if (in_array($metric, self::CUMULATIVE, true)) {
                $cumulative += $value;
                $value = round($cumulative / $size, 2);
            }
            $out[] = is_float($value) ? round($value, 2) : $value;
        }

        return $out;
    }

    /**
     * Shade (percent) per cell: retention = share of the cohort; orders/revenue
     * = share of the cohort's own Month 0; cumulative metrics = share of the
     * grid's largest value (they only grow, so "% of Month 0" says nothing).
     *
     * @param  list<array{month: string, size: int, values: list<float|int>}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function withCells(array $rows, string $metric): array
    {
        $max = 0.0;
        foreach ($rows as $r) {
            foreach ($r['values'] as $v) {
                $max = max($max, (float) $v);
            }
        }

        foreach ($rows as &$r) {
            $base = (float) ($r['values'][0] ?? 0);
            $r['cells'] = array_map(static function ($v) use ($metric, $r, $base, $max): ?float {
                if ($r['size'] === 0) {
                    return null;
                }

                return match (true) {
                    $metric === self::METRIC_RETENTION => round((float) $v / $r['size'] * 100, 1),
                    in_array($metric, self::CUMULATIVE, true) => $max > 0 ? round((float) $v / $max * 99.9, 1) : 0.0,
                    default => $base > 0 ? round((float) $v / $base * 100, 1) : null,
                };
            }, $r['values']);
        }

        return $rows;
    }

    /**
     * Is a member active at $t, replaying its movements (oldest first)? A
     * subscriber is active when ANY of their subscriptions is.
     *
     * @param  array<string, list<array{0: string, 1: int}>>  $moves  sub id => [[at, dir], …]
     */
    public static function activeAt(array $moves, CarbonImmutable $t): bool
    {
        $at = $t->format('Y-m-d H:i:s');
        foreach ($moves as $list) {
            $state = false;
            foreach ($list as [$when, $dir]) {
                if ($when > $at) {
                    break;
                }
                $state = $dir > 0;
            }
            if ($state) {
                return true;
            }
        }

        return false;
    }

    // === Members (cached) ===

    /** @return array{subscriber: list<array<string, mixed>>, subscription: list<array<string, mixed>>, has_contracts: bool} */
    private function members(CarbonImmutable $since): array
    {
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_KEY, $this->context->period, $filters, function () use ($since, $filters): array {
            $rows = (new MovementLog($filters))->since($since);

            $subs = [];
            foreach ($rows as $r) {
                if (! isset($subs[$r['sub']])) {
                    if ($r['type'] !== MovementLog::NEW) {
                        continue; // its life began before the window — not a joiner
                    }
                    $subs[$r['sub']] = ['sub' => $r['sub'], 'key' => $r['key'], 'joined' => $r['at'], 'moves' => []];
                }
                $subs[$r['sub']]['moves'][] = [$r['at'], $r['dir']];
            }

            [$orders, $revenue] = $this->charges(array_keys($subs), $subs);

            $subscriptions = [];
            $people = [];
            foreach ($subs as $id => $s) {
                $subscriptions[] = [
                    'joined' => $s['joined'],
                    'moves' => [$id => $s['moves']],
                    'orders' => $orders[$id] ?? [],
                    'revenue' => $revenue[$id] ?? [],
                ];
                $people[$s['key']][] = $id;
            }

            $returning = $this->returningPeople(array_map('strval', array_keys($people)), $since);
            $subscribers = [];
            foreach ($people as $key => $ids) {
                if (isset($returning[(string) $key])) {
                    continue;
                }
                $joined = min(array_map(static fn (string $id): string => $subs[$id]['joined'], $ids));
                $moves = [];
                $o = [];
                $rev = [];
                foreach ($ids as $id) {
                    $moves[$id] = $subs[$id]['moves'];
                    // Re-bucket the person's charges against THEIR join moment.
                    foreach ($this->chargeDays[$id] ?? [] as [$day, $n, $amount]) {
                        $m = self::monthIndex($joined, $day);
                        $o[$m] = ($o[$m] ?? 0) + $n;
                        $rev[$m] = ($rev[$m] ?? 0.0) + $amount;
                    }
                }
                $subscribers[] = ['joined' => $joined, 'moves' => $moves, 'orders' => $o, 'revenue' => $rev];
            }

            return [
                'subscriber' => $subscribers,
                'subscription' => $subscriptions,
                'has_contracts' => array_filter(array_keys($subs), static fn (string $id): bool => str_starts_with($id, 'c:')) !== [],
            ];
        }, $since->format('Y-m'));
    }

    /** @var array<string, list<array{0: string, 1: int, 2: float}>> sub id => [[day, orders, net amount]] */
    private array $chargeDays = [];

    /**
     * Orders and net revenue per subscription, bucketed by months since ITS join.
     *
     * @param  list<string>  $ids  'p:<plan id>' / 'c:<contract id>'
     * @param  array<string, array<string, mixed>>  $subs
     * @return array{0: array<string, array<int, int>>, 1: array<string, array<int, float>>}
     */
    private function charges(array $ids, array $subs): array
    {
        $plans = [];
        $contracts = [];
        foreach ($ids as $id) {
            str_starts_with($id, 'p:') ? $plans[] = (int) substr($id, 2) : $contracts[] = (int) substr($id, 2);
        }

        $this->chargeDays = [];
        foreach (array_chunk($plans, self::CHUNK) as $chunk) {
            $day = Sql::day('payment_ledger.created_at');
            $rows = PaymentLedger::query()
                ->whereIn('payment_ledger.plan_id', $chunk)
                ->whereIn('payment_ledger.status', self::PAID)
                ->selectRaw("payment_ledger.plan_id as id, {$day} as day, COUNT(*) as n, "
                    .'COALESCE(SUM(payment_ledger.amount - COALESCE(payment_ledger.refunded_amount, 0)), 0) as total')
                ->groupByRaw("payment_ledger.plan_id, {$day}")
                ->toBase()
                ->get();
            foreach ($rows as $r) {
                $this->chargeDays['p:'.$r->id][] = [(string) $r->day, (int) $r->n, (float) $r->total];
            }
        }

        foreach (array_chunk($contracts, self::CHUNK) as $chunk) {
            $at = 'COALESCE(subscription_billing_attempts.resolved_at, subscription_billing_attempts.requested_at, subscription_billing_attempts.created_at)';
            $day = Sql::day($at);
            $rows = SubscriptionBillingAttempt::query()
                ->join('subscription_contracts', function ($join): void {
                    $join->on('subscription_contracts.id', '=', 'subscription_billing_attempts.subscription_contract_id')
                        ->on('subscription_contracts.shop_id', '=', 'subscription_billing_attempts.shop_id');
                })
                ->whereIn('subscription_billing_attempts.subscription_contract_id', $chunk)
                ->where('subscription_billing_attempts.status', SubscriptionBillingAttempt::STATUS_SUCCEEDED)
                ->selectRaw("subscription_contracts.id as id, {$day} as day, COUNT(*) as n, COALESCE(SUM(subscription_contracts.amount), 0) as total")
                ->groupByRaw("subscription_contracts.id, {$day}")
                ->toBase()
                ->get();
            foreach ($rows as $r) {
                $this->chargeDays['c:'.$r->id][] = [(string) $r->day, (int) $r->n, (float) $r->total];
            }
        }

        $orders = [];
        $revenue = [];
        foreach ($this->chargeDays as $id => $days) {
            foreach ($days as [$day, $n, $amount]) {
                $m = self::monthIndex($subs[$id]['joined'], $day);
                $orders[$id][$m] = ($orders[$id][$m] ?? 0) + $n;
                $revenue[$id][$m] = round(($revenue[$id][$m] ?? 0.0) + $amount, 2);
            }
        }

        return [$orders, $revenue];
    }

    /** Whole months from a join moment to a charge day (a charge minutes before activation is Month 0). */
    public static function monthIndex(string $joined, string $day): int
    {
        $from = CarbonImmutable::parse(substr($joined, 0, 10));
        $to = CarbonImmutable::parse(substr($day, 0, 10));
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        return (int) floor($from->diffInMonths($to, true));
    }

    /**
     * People (customer keys) who had a subscription created before the window —
     * they joined earlier and belong to no cohort in it.
     *
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function returningPeople(array $keys, CarbonImmutable $since): array
    {
        $filters = $this->context->filters;
        $out = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $plans = $filters->applyToPlans(InstallmentPlan::query()
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->where('installment_plans.created_at', '<', $since)
                ->whereIn(DB::raw(Sql::planCustomerKey()), $chunk))
                ->selectRaw(Sql::planCustomerKey().' as k')
                ->distinct()
                ->toBase()
                ->pluck('k');
            foreach ($plans as $k) {
                $out[(string) $k] = true;
            }

            if ($filters->includesContracts()) {
                $contracts = $filters->applyToContracts(SubscriptionContract::query()
                    ->where('subscription_contracts.created_at', '<', $since)
                    ->whereIn(DB::raw(Sql::contractCustomerKey()), $chunk))
                    ->selectRaw(Sql::contractCustomerKey().' as k')
                    ->distinct()
                    ->toBase()
                    ->pluck('k');
                foreach ($contracts as $k) {
                    $out[(string) $k] = true;
                }
            }
        }

        return $out;
    }
}
