<?php

namespace App\Services\Shopify\Orders;

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
        $amount = round((float) ($plan->installment_amount ?? 0), 2);
        if ($amount <= 0) {
            return;
        }
        $creator->createPaidRecurringOrderForPayment($plan, $amount);
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
     * upsell: PHASE 6. A separate linked child order via draft-order-completed-as-
     * paid (ARCHITECTURE.md), linked to the parent by pps_main_order_id +
     * pps_order_role=upsell_child. Reuses ShopifyDraftOrderService's two-order
     * pattern. NOT built in this run.
     */
    private function onUpsell(InstallmentPlan $plan): void
    {
        // TODO(phase 6 — upsell engine): resolve the upsell flow/offer + saved
        //   token, charge via laravel-backend (idempotency key
        //   upsell:{shop}:{flow}:{offer}:{parent_order}:{customer}), then build the
        //   child order with ShopifyDraftOrderService::createForUpsell(...) and the
        //   inline sale transaction. Guard on the idempotency key so a
        //   double-clicked accept creates exactly ONE child order.
        Log::info('shopify.order_strategy.upsell_todo_phase6', ['plan_id' => $plan->id]);
    }
}
