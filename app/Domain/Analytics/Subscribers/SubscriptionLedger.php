<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Filters;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Database\Eloquent\Builder;

/**
 * The money of SUBSCRIPTIONS, as the Subscribers › Acquisition / Order funnel /
 * Revenue / Lifetime value screens read it (docs/analytics/data-map.md §1.2–1.5).
 *
 *   PayPlus rail  — payment_ledger rows of a RECURRING plan (charge_context
 *                   recurring or retry). Realised = succeeded, or refunded (the
 *                   row keeps its amount and records refunded_amount); money is
 *                   always amount − refunded_amount. A plan's CHECKOUT order is
 *                   its first realised row; every later one is RECURRING.
 *   Shopify rail  — subscription_billing_attempts (succeeded) priced at the
 *                   contract's amount. Every attempt is a RECURRING order: the
 *                   contract's checkout order lives in Shopify, never in LETS.
 *
 * Builders only — the query classes aggregate. Tenant law: both bases start
 * from BelongsToShop models; every join is pinned to the same shop_id.
 */
final class SubscriptionLedger
{
    // === CONSTANTS ===
    /** Ledger statuses that mean "the money was taken". */
    public const REALISED = [PaymentLedger::STATUS_SUCCEEDED, PaymentLedger::STATUS_REFUNDED];

    /** Ledger contexts that bill a subscription cycle. */
    public const CONTEXTS = [PaymentLedger::CONTEXT_RECURRING, PaymentLedger::CONTEXT_RETRY];

    public const KIND_CHECKOUT = 'checkout';

    public const KIND_RECURRING = 'recurring';

    /** Net money of a ledger row (refunds subtracted). */
    public const NET = '(payment_ledger.amount - COALESCE(payment_ledger.refunded_amount, 0))';

    public function __construct(private readonly Filters $filters) {}

    /** payment_ledger rows of recurring plans (any status), joined to their plan, chips applied. */
    public function planRows(): Builder
    {
        $query = PaymentLedger::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'payment_ledger.plan_id')
                    ->on('installment_plans.shop_id', '=', 'payment_ledger.shop_id');
            })
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->whereIn('payment_ledger.charge_context', self::CONTEXTS);

        return $this->filters->applyToPlans($query);
    }

    /** Realised rows only. */
    public function realisedPlanRows(): Builder
    {
        return $this->planRows()->whereIn('payment_ledger.status', self::REALISED);
    }

    /**
     * SQL: 'checkout' when this ledger row is its plan's FIRST realised charge,
     * else 'recurring'. Correlated on (shop_id, plan_id) — the ledger's index.
     */
    public static function kindExpression(): string
    {
        $realised = "'".implode("','", self::REALISED)."'";
        $contexts = "'".implode("','", self::CONTEXTS)."'";

        return '(CASE WHEN payment_ledger.id = (SELECT MIN(f.id) FROM payment_ledger f WHERE f.shop_id = payment_ledger.shop_id '
            ."AND f.plan_id = payment_ledger.plan_id AND f.status IN ({$realised}) AND f.charge_context IN ({$contexts})) "
            ."THEN '".self::KIND_CHECKOUT."' ELSE '".self::KIND_RECURRING."' END)";
    }

    /** Succeeded Shopify billing attempts joined to their contract, or null when the chips exclude contracts. */
    public function contractAttempts(?array $statuses = [SubscriptionBillingAttempt::STATUS_SUCCEEDED]): ?Builder
    {
        if (! $this->filters->includesContracts()) {
            return null;
        }

        $query = SubscriptionBillingAttempt::query()
            ->join('subscription_contracts', function ($join): void {
                $join->on('subscription_contracts.id', '=', 'subscription_billing_attempts.subscription_contract_id')
                    ->on('subscription_contracts.shop_id', '=', 'subscription_billing_attempts.shop_id');
            });
        if ($statuses !== null) {
            $query->whereIn('subscription_billing_attempts.status', $statuses);
        }

        return $this->filters->applyToContracts($query);
    }

    /** The moment an attempt counts at: resolved, else requested, else created. */
    public static function attemptAt(): string
    {
        return 'COALESCE(subscription_billing_attempts.resolved_at, subscription_billing_attempts.requested_at, subscription_billing_attempts.created_at)';
    }
}
