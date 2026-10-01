<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for the Products and Upsells Analytics tests: catalog products,
 * ledger rows, Shopify billing attempts, upsell flows/offers/events and
 * account-offer acceptances — each written under the shop it is given, in the
 * shape the live code writes it.
 */
trait BuildsProductsAndUpsells
{
    // === CONSTANTS ===
    protected static int $fixtureSeq = 0;

    protected function product(Shop $shop, string $externalId, string $title): void
    {
        DB::table('products')->insert([
            'shop_id' => $shop->getKey(), 'source' => 'woocommerce', 'external_id' => $externalId, 'title' => $title,
            'handle' => $externalId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function ledger(
        InstallmentPlan $plan,
        float $amount,
        CarbonImmutable $at,
        string $status = PaymentLedger::STATUS_SUCCEEDED,
        float $refunded = 0,
        string $context = PaymentLedger::CONTEXT_RECURRING,
    ): int {
        return DB::table('payment_ledger')->insertGetId([
            'shop_id' => $plan->shop_id, 'plan_id' => $plan->getKey(), 'charge_context' => $context,
            'idempotency_key' => 'pu:'.(++self::$fixtureSeq), 'amount' => $amount, 'refunded_amount' => $refunded,
            'currency' => 'ILS', 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    protected function billed(SubscriptionContract $contract, CarbonImmutable $at): void
    {
        DB::table('subscription_billing_attempts')->insert([
            'shop_id' => $contract->shop_id, 'subscription_contract_id' => $contract->getKey(),
            'billing_cycle_key' => 'pu'.(++self::$fixtureSeq), 'idempotency_key' => 'pu-sba:'.self::$fixtureSeq,
            'status' => SubscriptionBillingAttempt::STATUS_SUCCEEDED, 'requested_at' => $at, 'resolved_at' => $at,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @param list<array{product_id: string, quantity: int}> $lines */
    protected function withLines(SubscriptionContract $contract, array $lines): SubscriptionContract
    {
        $contract->forceFill(['lines' => $lines])->saveQuietly();

        return $contract->fresh();
    }

    /** A status move carrying a reason, as SubscriptionLifecycleService writes it. */
    protected function moveWithReason(InstallmentPlan $plan, string $from, string $to, CarbonImmutable $at, ?string $reason): void
    {
        $event = new ActivityEvent;
        $event->forceFill([
            'shop_id' => $plan->shop_id, 'plan_id' => $plan->getKey(), 'payment_id' => null, 'actor' => 'customer',
            'kind' => Timeline::KIND_STATUS_CHANGED,
            'details' => array_filter(['model' => 'InstallmentPlan', 'from' => $from, 'to' => $to, 'action' => 'cancelled', 'reason' => $reason]),
            'created_at' => $at,
        ])->save();
    }

    /** @return array{flow: int, offer: int} */
    protected function upsellOffer(Shop $shop, string $name, float $base, string $discountType = 'none', float $discount = 0, string $title = 'Mug'): array
    {
        $flow = DB::table('upsell_flows')->insertGetId([
            'shop_id' => $shop->getKey(), 'name' => $name, 'status' => 'active', 'priority' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $offer = DB::table('upsell_flow_offers')->insertGetId([
            'shop_id' => $shop->getKey(), 'flow_id' => $flow, 'offer_product_gid' => 'p', 'offer_variant_gid' => 'v',
            'offer_title' => $title, 'base_price' => $base, 'discount_type' => $discountType, 'discount_value' => $discount,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['flow' => $flow, 'offer' => $offer];
    }

    /** @param array{flow: int, offer: int} $offer */
    protected function upsellEvent(Shop $shop, array $offer, string $type, CarbonImmutable $at, string $order, ?float $revenue = null, ?int $ledgerId = null): void
    {
        DB::table('upsell_offer_events')->insert([
            'shop_id' => $shop->getKey(), 'flow_id' => $offer['flow'], 'offer_id' => $offer['offer'], 'event_type' => $type,
            'revenue_amount' => $revenue, 'currency' => 'ILS', 'parent_order_id' => $order, 'customer_ref' => 'c-'.$order,
            'payment_ledger_id' => $ledgerId, 'occurred_at' => $at, 'created_at' => $at,
        ]);
    }

    /** An upsell charge's own ledger row (no plan: upsell is a context, not a plan). */
    protected function upsellLedger(Shop $shop, float $amount, CarbonImmutable $at, float $refunded = 0, string $context = PaymentLedger::CONTEXT_UPSELL): int
    {
        return DB::table('payment_ledger')->insertGetId([
            'shop_id' => $shop->getKey(), 'plan_id' => null, 'charge_context' => $context,
            'idempotency_key' => 'pu-up:'.(++self::$fixtureSeq), 'amount' => $amount, 'refunded_amount' => $refunded,
            'currency' => 'ILS', 'status' => $refunded >= $amount && $refunded > 0 ? PaymentLedger::STATUS_REFUNDED : PaymentLedger::STATUS_SUCCEEDED,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    protected function accountOffer(Shop $shop, string $name): int
    {
        return DB::table('account_offers')->insertGetId([
            'shop_id' => $shop->getKey(), 'name' => $name, 'status' => 'active', 'placement' => 'plan', 'priority' => 0,
            'accepted_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An account_offer_accepted Timeline row on $plan. */
    protected function accepted(InstallmentPlan $plan, array $details, CarbonImmutable $at): void
    {
        DB::table('activity_events')->insert([
            'shop_id' => $plan->shop_id, 'plan_id' => $plan->getKey(), 'payment_id' => null, 'actor' => 'customer',
            'kind' => Timeline::KIND_ACCOUNT_OFFER_ACCEPTED, 'details' => json_encode($details), 'created_at' => $at,
        ]);
    }
}
