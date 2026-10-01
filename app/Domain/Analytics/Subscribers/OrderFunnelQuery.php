<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Facades\DB;

/**
 * Subscribers › Order funnel — the numbers (spec §1.3, data-map §1.3).
 *
 * ORDER-WISE ACTIVE: today's active subscriptions by completed orders —
 *   PayPlus: realised ledger rows of the plan; Shopify: 1 (the checkout order
 *   Shopify placed) + succeeded billing attempts.
 *
 * ATTEMPTED → OUTCOME, in the window: one payment_ledger row per billed cycle
 *   (a retry re-uses the row, so its status is the cycle's final outcome) and
 *   one subscription_billing_attempts row per Shopify cycle. A cycle's ORDER
 *   NUMBER is its position in its subscription's billing history.
 *     succeeded / refunded        → success
 *     retry_scheduled             → failed, to be retried
 *     failed                      → failed, no retry left
 *     pending (Shopify requested/challenged) → in progress
 *   DUNNING = a cycle that has failed at least once (failed / retry_scheduled
 *   now, or a charge_failed Timeline row on its payment slot).
 *
 * NOT TRACKED: the "scheduled" stage and its leaks (rescheduled, skipped,
 * paused/cancelled before the order) — a due charge that was never attempted
 * leaves no row, and the scheduled date at pause/cancel time is not stored.
 * Ledger rows in `cancelled` (an attempt voided before the provider) are not
 * attempts and are left out.
 *
 * Everything is aggregated in SQL (GROUP BY bucket × outcome × dunning).
 */
final class OrderFunnelQuery
{
    // === CONSTANTS ===
    public const CACHE_ORDER_WISE = 'order_funnel.order_wise';

    public const CACHE_OUTCOMES = 'order_funnel.outcomes';

    /** Order-wise active subscriptions: buckets 0…11 and "12+". */
    public const ORDER_WISE_CAP = 12;

    /** Leakage table: order numbers #1…#7 and "#8+". */
    public const LEAKAGE_CAP = 8;

    public const SUCCESS = 'success';

    public const RETRYING = 'retrying';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const OUTCOMES = [self::SUCCESS, self::RETRYING, self::FAILED, self::PENDING];

    public const MODE_ALL = 'all';

    public const MODE_DUNNING = 'dunning';

    public const MODE_NON_DUNNING = 'non_dunning';

    public const MODES = [self::MODE_ALL, self::MODE_DUNNING, self::MODE_NON_DUNNING];

    /** Ledger status → outcome. `cancelled` is absent on purpose (not an attempt). */
    public const LEDGER_OUTCOME = [
        PaymentLedger::STATUS_SUCCEEDED => self::SUCCESS,
        PaymentLedger::STATUS_REFUNDED => self::SUCCESS,
        PaymentLedger::STATUS_RETRY_SCHEDULED => self::RETRYING,
        PaymentLedger::STATUS_FAILED => self::FAILED,
        PaymentLedger::STATUS_PENDING => self::PENDING,
    ];

    public const ATTEMPT_OUTCOME = [
        SubscriptionBillingAttempt::STATUS_SUCCEEDED => self::SUCCESS,
        SubscriptionBillingAttempt::STATUS_FAILED => self::FAILED,
        SubscriptionBillingAttempt::STATUS_REQUESTED => self::PENDING,
        SubscriptionBillingAttempt::STATUS_CHALLENGED => self::PENDING,
    ];

    public function __construct(private readonly Context $context) {}

    /**
     * @return array{
     *   order_wise: array<int, int>,
     *   funnel: array<string, int>,
     *   leakage: array<int, array<string, int>>,
     *   has_data: bool
     * }
     */
    public function get(string $mode = self::MODE_ALL): array
    {
        $orderWise = $this->orderWise();
        $outcomes = $this->outcomes();

        $funnel = array_fill_keys(self::OUTCOMES, 0);
        $leakage = [];
        foreach ($outcomes as $row) {
            if ($mode === self::MODE_DUNNING && ! $row['dunning'] || $mode === self::MODE_NON_DUNNING && $row['dunning']) {
                continue;
            }
            $leakage[$row['bucket']] ??= array_fill_keys(self::OUTCOMES, 0);
            $leakage[$row['bucket']][$row['outcome']] += $row['n'];
            $funnel[$row['outcome']] += $row['n'];
        }
        ksort($leakage);

        return [
            'order_wise' => $orderWise,
            'funnel' => $funnel,
            'leakage' => $leakage,
            'has_data' => array_sum($orderWise) > 0 || $outcomes !== [],
        ];
    }

    /** @return array<int, int> completed orders (capped) => active subscriptions */
    public function orderWise(): array
    {
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_ORDER_WISE, $this->context->period, $filters, function () use ($filters): array {
            $cap = self::ORDER_WISE_CAP;
            $realised = "'".implode("','", SubscriptionLedger::REALISED)."'";
            $contexts = "'".implode("','", SubscriptionLedger::CONTEXTS)."'";
            $orders = '(SELECT COUNT(*) FROM payment_ledger f WHERE f.shop_id = installment_plans.shop_id '
                ."AND f.plan_id = installment_plans.id AND f.status IN ({$realised}) AND f.charge_context IN ({$contexts}))";

            $plans = $filters->applyToPlans(
                InstallmentPlan::query()
                    ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                    ->whereIn('installment_plans.status', ActiveBook::PLAN_ACTIVE)
            )->selectRaw("{$orders} as n")->toBase();

            $out = [];
            $add = static function (iterable $rows) use (&$out): void {
                foreach ($rows as $r) {
                    $out[(int) $r->b] = ($out[(int) $r->b] ?? 0) + (int) $r->subs;
                }
            };
            $add(DB::query()->fromSub($plans, 'u')
                ->selectRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END as b, COUNT(*) as subs")
                ->groupByRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END")->get());

            if ($filters->includesContracts()) {
                $attempts = '(SELECT COUNT(*) FROM subscription_billing_attempts a WHERE a.shop_id = subscription_contracts.shop_id '
                    ."AND a.subscription_contract_id = subscription_contracts.id AND a.status = '".SubscriptionBillingAttempt::STATUS_SUCCEEDED."')";
                $contracts = $filters->applyToContracts(
                    SubscriptionContract::query()->whereIn('subscription_contracts.status', ActiveBook::CONTRACT_ACTIVE)
                )->selectRaw("(1 + {$attempts}) as n")->toBase();
                $add(DB::query()->fromSub($contracts, 'u')
                    ->selectRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END as b, COUNT(*) as subs")
                    ->groupByRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END")->get());
            }
            ksort($out);

            return $out;
        });
    }

    /** @return list<array{bucket: int, outcome: string, dunning: bool, n: int}> */
    public function outcomes(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_OUTCOMES, $period, $filters, function () use ($period, $filters): array {
            $cap = self::LEAKAGE_CAP;
            $out = [];

            // PayPlus: one ledger row per billed cycle.
            $contexts = "'".implode("','", SubscriptionLedger::CONTEXTS)."'";
            $orderNo = '(1 + (SELECT COUNT(*) FROM payment_ledger o WHERE o.shop_id = payment_ledger.shop_id '
                ."AND o.plan_id = payment_ledger.plan_id AND o.charge_context IN ({$contexts}) AND o.id < payment_ledger.id))";
            $failedOnce = "(CASE WHEN payment_ledger.status IN ('".PaymentLedger::STATUS_FAILED."', '".PaymentLedger::STATUS_RETRY_SCHEDULED."') "
                .'OR (payment_ledger.payment_id IS NOT NULL AND EXISTS (SELECT 1 FROM activity_events ae '
                .'WHERE ae.shop_id = payment_ledger.shop_id AND ae.plan_id = payment_ledger.plan_id '
                ."AND ae.payment_id = payment_ledger.payment_id AND ae.kind = '".Timeline::KIND_CHARGE_FAILED."')) THEN 1 ELSE 0 END)";

            $ledger = (new SubscriptionLedger($filters))->planRows()
                ->whereIn('payment_ledger.status', array_keys(self::LEDGER_OUTCOME))
                ->whereBetween('payment_ledger.created_at', [$period->start(), $period->end()])
                ->selectRaw("{$orderNo} as n, payment_ledger.status as st, {$failedOnce} as dun")
                ->toBase();

            foreach (DB::query()->fromSub($ledger, 'u')
                ->selectRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END as b, st, dun, COUNT(*) as c")
                ->groupByRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END, st, dun")->get() as $r) {
                $out[] = ['bucket' => (int) $r->b, 'outcome' => self::LEDGER_OUTCOME[$r->st], 'dunning' => (bool) $r->dun, 'n' => (int) $r->c];
            }

            // Shopify: one billing attempt per cycle; order #1 was the checkout.
            $attempts = (new SubscriptionLedger($filters))->contractAttempts(array_keys(self::ATTEMPT_OUTCOME));
            if ($attempts !== null) {
                $at = SubscriptionLedger::attemptAt();
                $orderNo = '(2 + (SELECT COUNT(*) FROM subscription_billing_attempts o WHERE o.shop_id = subscription_billing_attempts.shop_id '
                    .'AND o.subscription_contract_id = subscription_billing_attempts.subscription_contract_id AND o.id < subscription_billing_attempts.id))';
                $attempts->whereRaw("{$at} >= ? AND {$at} <= ?", [$period->start(), $period->end()])
                    ->selectRaw("{$orderNo} as n, subscription_billing_attempts.status as st");

                foreach (DB::query()->fromSub($attempts->toBase(), 'u')
                    ->selectRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END as b, st, COUNT(*) as c")
                    ->groupByRaw("CASE WHEN n >= {$cap} THEN {$cap} ELSE n END, st")->get() as $r) {
                    $outcome = self::ATTEMPT_OUTCOME[$r->st];
                    $out[] = ['bucket' => (int) $r->b, 'outcome' => $outcome, 'dunning' => $outcome === self::FAILED, 'n' => (int) $r->c];
                }
            }

            return $out;
        });
    }
}
