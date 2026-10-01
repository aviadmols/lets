<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Support\Sql;
use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Models\ActivityEvent;
use App\Models\SubscriptionBillingAttempt;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;

/**
 * Every CANCELLATION of a subscription between two moments, both rails, with
 * the facts the Cancellations screens slice by: reason, channel, product,
 * cadence, selling plan, MRR and the orders it completed before it went.
 *
 * "Cancellation" is exactly MovementLog's CANCELLED type, so the counts here
 * always equal the Subscribers screens' "Cancelled":
 *   PayPlus — status_changed active → cancelled | failed | awaiting_payment
 *             (the last two are involuntary churn, channel "Payment failed" —
 *             candidates only: each carries its recovery time, and a window
 *             counts it only if the plan is still lapsed at the window's end,
 *             MovementLog::countsIn via ChurnData::inWindow);
 *   Shopify — the shopify_subscription_cancelled Timeline kind.
 *
 * Volume: one row per cancellation in the window. Completed orders are a
 * correlated COUNT per row (succeeded ledger rows / billing attempts written
 * up to the cancellation), so it is the history AT cancel time.
 */
final class CancellationLog
{
    // === CONSTANTS ===
    /** Ledger contexts that are an ORDER of the subscription (an upsell is not). */
    public const EXCLUDED_CONTEXTS = ['upsell'];

    public const RAIL_PLAN = 'plan';

    public const RAIL_CONTRACT = 'contract';

    /** Product key every contract shares (contracts carry no product we key on). */
    public const PRODUCT_CONTRACTS = '__contracts';

    public const PRODUCT_UNKNOWN = '__unknown';

    public function __construct(private readonly Filters $filters) {}

    /**
     * @return list<array{at: string, day: string, sub: string, rail: string, key: string, mrr: float, born: string,
     *   actor: string, reason: string, reason_key: string, channel: string, to: string, product: string, product_title: string,
     *   freq: string, sp: string, sp_name: ?string, orders: int, amount: float, name: string, email: string, ref: string}>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->plans($from, $to);
        if ($this->filters->includesContracts()) {
            array_push($rows, ...$this->contracts($from, $to));
        }
        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['sub']] <=> [$b['at'], $b['sub']]);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function plans(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromStatus = Sql::jsonText('activity_events.details', 'from');
        $toStatus = Sql::jsonText('activity_events.details', 'to');
        $reason = Sql::jsonText('activity_events.details', 'reason');
        $productId = "COALESCE(NULLIF(installment_plans.external_product_id, ''), NULLIF(installment_plans.shopify_product_id, ''))";
        $title = 'COALESCE((SELECT MAX(pr.title) FROM products pr WHERE pr.shop_id = installment_plans.shop_id AND pr.external_id = '
            .$productId.'), '.Sql::jsonText('installment_plans.meta', 'product_title').', '.Sql::jsonText('installment_plans.meta', 'item_title').')';
        $orders = '(SELECT COUNT(*) FROM payment_ledger pl WHERE pl.shop_id = installment_plans.shop_id AND pl.plan_id = installment_plans.id'
            ." AND pl.status = '".LedgerStatus::SUCCEEDED->value."'"
            ." AND pl.charge_context NOT IN ('".implode("','", self::EXCLUDED_CONTEXTS)."')"
            .' AND pl.created_at <= activity_events.created_at)';
        $ends = [PlanStatus::CANCELLED->value, ...MovementLog::LAPSED];
        // When a payment-retry lapse came back: the plan's first lapsed → active
        // move after it. One correlated MIN per cancellation row (window-sized).
        $lapsedList = "'".implode("','", MovementLog::LAPSED)."'";
        $recFrom = Sql::jsonText('rec.details', 'from');
        $recTo = Sql::jsonText('rec.details', 'to');
        $recovered = "(CASE WHEN {$toStatus} IN ({$lapsedList}) THEN (SELECT MIN(rec.created_at) FROM activity_events rec"
            .' WHERE rec.shop_id = activity_events.shop_id AND rec.plan_id = activity_events.plan_id'
            ." AND rec.kind = '".Timeline::KIND_STATUS_CHANGED."' AND rec.payment_id IS NULL"
            ." AND rec.created_at >= activity_events.created_at AND rec.id <> activity_events.id"
            ." AND {$recFrom} IN ({$lapsedList}) AND {$recTo} = '".PlanStatus::ACTIVE->value."') END)";

        $query = ActivityEvent::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'activity_events.plan_id')
                    ->on('installment_plans.shop_id', '=', 'activity_events.shop_id');
            })
            ->leftJoin('product_subscription_plans as psp', function ($join): void {
                $join->on('psp.id', '=', 'installment_plans.product_subscription_plan_id')
                    ->on('psp.shop_id', '=', 'installment_plans.shop_id');
            })
            ->where('activity_events.kind', Timeline::KIND_STATUS_CHANGED)
            ->whereNull('activity_events.payment_id')
            ->whereBetween('activity_events.created_at', [$from, $to])
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->whereRaw("{$fromStatus} = ?", [PlanStatus::ACTIVE->value])
            ->whereRaw("{$toStatus} IN ('".implode("','", $ends)."')");

        $rows = $this->filters->applyToPlans($query)
            ->selectRaw(implode(', ', [
                'activity_events.created_at as at',
                'activity_events.actor as actor',
                "{$toStatus} as to_status",
                "{$reason} as reason",
                'installment_plans.id as id',
                'installment_plans.public_id as ref',
                Sql::planCustomerKey().' as k',
                'installment_plans.created_at as born',
                Sql::planMrr().' as mrr',
                'installment_plans.installment_amount as amount',
                "{$productId} as product",
                "{$title} as product_title",
                Sql::planFrequencyKey().' as freq',
                "COALESCE(CAST(installment_plans.product_subscription_plan_id AS TEXT), 'none') as sp",
                'psp.plan_name as sp_name',
                "{$orders} as orders",
                'installment_plans.customer_name as name',
                'installment_plans.customer_email as email',
                "{$recovered} as recovered_at",
            ]))
            ->toBase()
            ->get();

        return $rows->map(fn ($r): array => $this->row(
            $r, 'p:'.$r->id, self::RAIL_PLAN,
            (string) ($r->product ?? '') !== '' ? (string) $r->product : self::PRODUCT_UNKNOWN,
        ))->all();
    }

    /** @return list<array<string, mixed>> */
    private function contracts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $gid = Sql::jsonText('activity_events.details', 'contract_gid');
        $reason = Sql::jsonText('activity_events.details', 'reason');
        $orders = '(SELECT COUNT(*) FROM subscription_billing_attempts sba WHERE sba.shop_id = subscription_contracts.shop_id'
            .' AND sba.subscription_contract_id = subscription_contracts.id'
            ." AND sba.status = '".SubscriptionBillingAttempt::STATUS_SUCCEEDED."'"
            .' AND sba.created_at <= activity_events.created_at)';

        $query = ActivityEvent::query()
            ->join('subscription_contracts', function ($join) use ($gid): void {
                $join->on('subscription_contracts.shop_id', '=', 'activity_events.shop_id')
                    ->whereRaw("subscription_contracts.shopify_gid = {$gid}");
            })
            ->where('activity_events.kind', ContractActionService::KIND_CANCELLED)
            ->whereBetween('activity_events.created_at', [$from, $to]);

        $rows = $this->filters->applyToContracts($query)
            ->selectRaw(implode(', ', [
                'activity_events.created_at as at',
                'activity_events.actor as actor',
                "'".PlanStatus::CANCELLED->value."' as to_status",
                "{$reason} as reason",
                'subscription_contracts.id as id',
                'subscription_contracts.shopify_gid as ref',
                Sql::contractCustomerKey().' as k',
                'subscription_contracts.created_at as born',
                Sql::contractMrr().' as mrr',
                'subscription_contracts.amount as amount',
                'CAST(NULL AS TEXT) as product_title',
                Sql::contractFrequencyKey().' as freq',
                "'shopify' as sp",
                'CAST(NULL AS TEXT) as sp_name',
                "{$orders} as orders",
                'subscription_contracts.customer_name as name',
                'subscription_contracts.customer_email as email',
            ]))
            ->toBase()
            ->get();

        return $rows->map(fn ($r): array => $this->row($r, 'c:'.$r->id, self::RAIL_CONTRACT, self::PRODUCT_CONTRACTS))->all();
    }

    /** @return array<string, mixed> */
    private function row(object $r, string $sub, string $rail, string $product): array
    {
        $at = CarbonImmutable::parse((string) $r->at);
        $born = CarbonImmutable::parse((string) ($r->born ?? $r->at));
        $to = (string) ($r->to_status ?? PlanStatus::CANCELLED->value);
        $reason = (string) ($r->reason ?? '');

        return [
            'at' => $at->format('Y-m-d H:i:s'),
            'day' => $at->format('Y-m-d'),
            'sub' => $sub,
            'rail' => $rail,
            'key' => (string) $r->k,
            'mrr' => round((float) $r->mrr, 2),
            'born' => $born->format('Y-m-d'),
            'actor' => (string) ($r->actor ?? ActivityEvent::ACTOR_SYSTEM),
            'reason' => CancellationReasons::clean($reason),
            'reason_key' => CancellationReasons::reasonKey($reason, $to),
            'channel' => CancellationReasons::channel($r->actor, $reason, $to),
            'to' => $to,
            'product' => $product,
            'product_title' => trim((string) ($r->product_title ?? '')),
            'freq' => (string) $r->freq,
            'sp' => (string) $r->sp,
            'sp_name' => $r->sp_name !== null ? (string) $r->sp_name : null,
            'orders' => (int) $r->orders,
            'amount' => round((float) ($r->amount ?? 0), 2),
            'name' => (string) ($r->name ?? ''),
            'email' => (string) ($r->email ?? ''),
            'ref' => (string) ($r->ref ?? ''),
            // MovementLog::countsIn reads these: a lapse recovered inside a window is not its cancellation.
            'dunning' => in_array($to, MovementLog::LAPSED, true) ? MovementLog::DUNNING_OUT : null,
            'paired_at' => isset($r->recovered_at) ? CarbonImmutable::parse((string) $r->recovered_at)->format('Y-m-d H:i:s') : null,
        ];
    }
}
