<?php

namespace App\Services\Shopify\Orders;

use App\Domain\Billing\CycleAmountResolver;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Default ShopifyOrderStrategy. Dispatches by charge_context to the ported,
 * multi-tenant order services, resolving the per-shop Admin client via
 * ShopifyClientFactory::for($plan->shop). Called by the orchestrator AFTER a
 * succeeded ledger row — never speculatively.
 *
 * Each path is idempotent (guards on shopify_order_id / metafield state) so a
 * double-fired webhook or a retried job never creates a second order — "the
 * webhook fired twice and we created two child orders" is designed out here.
 *
 * UPSELL is Phase 6: it materializes a separate linked child order via
 * draft-order-completed-as-paid (ARCHITECTURE.md locked decision). The draft path
 * is stubbed below with a clear TODO — do NOT build it in this run.
 */
final class DefaultShopifyOrderStrategy implements ShopifyOrderStrategy
{
    public function materialize(InstallmentPlan $plan, ChargeContext $context, bool $isFinal = false): void
    {
        // Never touch Shopify for a shop that cannot be called (uninstalled /
        // never-connected token). hasShopifyConnection() requires BOTH a live
        // status AND a decryptable token, so we skip cleanly instead of letting
        // the factory throw on a tokenless shop.
        $shop = $plan->shop;
        if ($shop === null || ! $shop->hasShopifyConnection()) {
            Log::info('shopify.order_strategy.skipped_inactive_shop', ['plan_id' => $plan->id]);

            return;
        }

        $client = ShopifyClientFactory::for($shop);
        $creator = new ShopifyOrderCreator($client);
        $lock = new FulfillmentLockService($client);

        match ($context) {
            ChargeContext::DEPOSIT => $this->onDeposit($plan, $creator),
            ChargeContext::INSTALLMENT => $this->onInstallment($plan, $lock, $isFinal),
            ChargeContext::RECURRING => $this->onRecurring($plan, $creator),
            ChargeContext::UPSELL => $this->onUpsell($plan),
            // retry/manual re-enter the matching context upstream; here they are
            // resolved to the plan's own kind by the orchestrator before calling.
            ChargeContext::RETRY, ChargeContext::MANUAL => $this->onRecurringOrInstallment($plan, $creator, $lock, $isFinal),
        };
    }

    /** deposit: create the LOCKED parent order (no transactions), persist its ids. */
    private function onDeposit(InstallmentPlan $plan, ShopifyOrderCreator $creator): void
    {
        $result = $creator->createMainOrderForPlan($plan);

        // Persist via forceFill (shopify_order_id is not in a state-machine column).
        $plan->forceFill([
            'shopify_order_id' => $result['shopify_order_id'],
            'shopify_order_gid' => $result['shopify_order_gid'],
        ])->save();
    }

    /** installment: update parent metafields; on final, release fulfillment. */
    private function onInstallment(InstallmentPlan $plan, FulfillmentLockService $lock, bool $isFinal): void
    {
        $lock->updateProgressMetafields($plan);

        if ($isFinal && $plan->isFullyPaid()) {
            $lock->release($plan);
        }
    }

    /** recurring: a NEW fulfillable order per cycle (failed cycle ⇒ no order). */
    private function onRecurring(InstallmentPlan $plan, ShopifyOrderCreator $creator): void
    {
        // The shared resolver returns the money that ACTUALLY moved for this cycle
        // (the succeeded slot's frozen amount → override → the plan ladder), so the
        // order can never disagree with the ledger. This also honors the W25
        // next-order override the old installment_amount read silently ignored.
        $resolver = new CycleAmountResolver;
        $amount = $resolver->amountForCycleOrder($plan);
        if ($amount <= 0) {
            return;
        }

        // Discount-tag predicate: the cycle charged BELOW the plan's regular
        // (undiscounted) price — intro window active, or a kept first payment.
        $discounted = $plan->regular_amount !== null
            && $amount < round((float) $plan->regular_amount, 2);

        $creator->createPaidRecurringOrderForPayment($plan, $amount, $discounted);
    }

    /** retry/manual fall through to the plan's underlying kind. */
    private function onRecurringOrInstallment(InstallmentPlan $plan, ShopifyOrderCreator $creator, FulfillmentLockService $lock, bool $isFinal): void
    {
        if ($plan->isRecurring()) {
            $this->onRecurring($plan, $creator);

            return;
        }
        $this->onInstallment($plan, $lock, $isFinal);
    }

    /**
     * upsell: handled OUT-OF-BAND by the Phase-6 upsell engine, NOT through this
     * plan-keyed strategy. The post-purchase upsell is a charge CONTEXT, not a
     * plan, so it never reaches materialize(ChargeContext::UPSELL). The real path:
     *   App\Domain\Upsell\UpsellChargeService::accept() charges the saved token
     *   (idempotency key upsell:{shop}:{flow}:{offer}:{parent}:{customer}) then
     *   builds the linked child order via
     *   ShopifyDraftOrderService::createUpsellChildOrderForCustomer(...).
     *
     * This branch is defensive only — if an upsell context is ever routed here we
     * log and no-op rather than create a duplicate child order.
     */
    private function onUpsell(InstallmentPlan $plan): void
    {
        Log::info('shopify.order_strategy.upsell_handled_by_charge_service', ['plan_id' => $plan->id]);
    }
}
