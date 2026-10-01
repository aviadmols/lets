<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use Carbon\CarbonImmutable;

/**
 * Money fixtures for the Subscribers › Acquisition / Order funnel / Revenue /
 * Lifetime value tests: payment_ledger rows on a plan and Shopify billing
 * attempts on a contract, written under the row's own shop.
 */
trait BuildsLedger
{
    // === CONSTANTS ===
    protected static int $ledgerSeq = 0;

    protected function charge(
        InstallmentPlan $plan,
        float $amount,
        CarbonImmutable $at,
        string $status = PaymentLedger::STATUS_SUCCEEDED,
        float $refunded = 0,
        string $context = PaymentLedger::CONTEXT_RECURRING,
    ): PaymentLedger {
        $row = new PaymentLedger;
        $row->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'charge_context' => $context,
            'idempotency_key' => 'test:'.$plan->getKey().':'.(++self::$ledgerSeq),
            'amount' => $amount,
            'refunded_amount' => $refunded,
            'currency' => 'ILS',
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        return $row;
    }

    protected function attempt(SubscriptionContract $contract, CarbonImmutable $at, string $status = SubscriptionBillingAttempt::STATUS_SUCCEEDED): SubscriptionBillingAttempt
    {
        $row = new SubscriptionBillingAttempt;
        $row->forceFill([
            'shop_id' => $contract->shop_id,
            'subscription_contract_id' => $contract->getKey(),
            'billing_cycle_key' => 'c'.(++self::$ledgerSeq),
            'idempotency_key' => 'sba:'.self::$ledgerSeq,
            'status' => $status,
            'requested_at' => $at,
            'resolved_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        return $row;
    }
}
