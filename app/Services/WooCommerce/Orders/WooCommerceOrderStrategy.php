<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Billing\CycleAmountResolver;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Services\Orders\PlatformOrderStrategy;
use App\Services\WooCommerce\WooClientFactory;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCustomerResolver;
use App\Services\WooCommerce\WooOrderAddress;
use Illuminate\Support\Facades\Log;

/**
 * WooCommerce implementation of PlatformOrderStrategy (W11 P3) — the WC analogue of
 * DefaultShopifyOrderStrategy. The ChargeOrchestrator decides TO CHARGE; AFTER a
 * succeeded ledger row exists it calls materialize() to materialize WC store state via
 * the WC REST API. We never create a fulfillable WC order for a charge that has not
 * succeeded (the orchestrator only reaches here after the ledger row is `succeeded`).
 *
 * Per charge_context (the §5 table):
 *   deposit              → ensure the parent WC order exists (status processing),
 *                          store lets_plan_public_id + the line item; fulfillment LOCKED
 *                          (NOT completed — released only when fully paid).
 *   installment          → update the parent order meta (paid / remaining / next charge).
 *   installment + final  → set the parent order `completed` (release fulfillment).
 *   recurring            → create a NEW PAID WC order per cycle, linked by meta. A failed
 *                          cycle creates NO order (we are only called after a success).
 *   upsell/retry/manual  → no-op (upsell is handled by UpsellChargeService; retry/manual
 *                          are resolved to the plan's own kind upstream before we run).
 *
 * Tenant law: the plan is BelongsToShop-scoped + carries shop_id; the per-shop WC client
 * is resolved via WooClientFactory::for($plan->shop) INSIDE materialize() (never a shared
 * constructor singleton — mirrors how the Shopify strategy resolves its client per shop).
 * Idempotent by construction: the parent order id is stored on external_order_id and a
 * recurring cycle's order id in plan meta, so a double-fired job never creates a second
 * order. Fail-soft: a store-side error is logged + swallowed (the money truth lives in
 * the ledger regardless), matching the orchestrator's wrapping of this call.
 */
final class WooCommerceOrderStrategy implements PlatformOrderStrategy
{
    // === CONSTANTS ===
    /** WC order meta linking an order to its LETS plan (read by WooCommercePaidOrderPlanResolver). */
    public const META_PLAN_PUBLIC_ID = 'lets_plan_public_id';
    public const META_ORDER_ROLE = 'lets_order_role';
    public const META_PAID_AMOUNT = 'lets_paid_amount';
    public const META_REMAINING_BALANCE = 'lets_remaining_balance';
    public const META_NEXT_CHARGE_AT = 'lets_next_charge_at';
    public const META_INSTALLMENT_STATUS = 'lets_installment_status';
    public const META_MAIN_ORDER_ID = 'lets_main_order_id';

    /** Order roles (mirror the Shopify pps_order_role note attribute). */
    private const ROLE_MAIN = 'main_order';
    private const ROLE_RECURRING = 'recurring_order';

    /** WC order statuses. */
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';

    /** Plan meta key holding the per-cycle recurring WC order ids (idempotency; read by DocumentIssuer to link a cycle receipt to its order). */
    public const META_RECURRING_ORDER_IDS = 'wc_recurring_order_ids';

    /** Plan meta key remembering the WC customer resolved by email (owned by WooCustomerResolver). */
    public const META_WC_CUSTOMER_ID = WooCustomerResolver::META_WC_CUSTOMER_ID;

    public function materialize(InstallmentPlan $plan, ChargeContext $context, bool $isFinal = false): void
    {
        // Never call WC for a shop that has not completed the connect handshake — skip
        // cleanly instead of letting WooClientFactory throw on missing REST creds.
        $shop = $plan->shop;
        if ($shop === null || ! $shop->hasWooConnection()) {
            Log::info('woocommerce.order_strategy.skipped_unconnected_shop', ['plan_id' => $plan->getKey()]);

            return;
        }

        $client = WooClientFactory::for($shop);

        match ($context) {
            ChargeContext::DEPOSIT => $this->onDeposit($plan, $client),
            ChargeContext::INSTALLMENT => $this->onInstallment($plan, $client, $isFinal),
            ChargeContext::RECURRING => $this->onRecurring($plan, $client),
            ChargeContext::UPSELL => $this->onUpsell($plan),
            // retry/manual re-enter the plan's own kind (the orchestrator resolves the
            // concrete context upstream; we route by the plan's kind defensively).
            ChargeContext::RETRY, ChargeContext::MANUAL => $this->onRecurringOrInstallment($plan, $client, $isFinal),
        };
    }

    /** deposit: ensure the LOCKED parent WC order exists (processing, not completed). */
    private function onDeposit(InstallmentPlan $plan, WooCommerceClient $client): void
    {
        // Idempotent: if we already materialized a parent order, do nothing.
        if (($existing = $plan->externalOrderId()) !== null && $existing !== '') {
            return;
        }

        $customerId = $this->customerIdFor($plan, $client);
        $address = WooOrderAddress::forOrder($plan, $plan->shop, $customerId);

        $order = $client->createOrder([
            'status' => self::STATUS_PROCESSING,        // accepted, fulfillment LOCKED (not completed)
            'set_paid' => false,                        // the deposit was collected on the PayPlus page, not WC
            'currency' => (string) $plan->currency,
            'customer_id' => $customerId,
            'billing' => $address['billing'],
            'shipping' => $address['shipping'],
            'line_items' => [$this->mainLineItem($plan)],
            'meta_data' => $this->meta([
                self::META_PLAN_PUBLIC_ID => (string) $plan->public_id,
                self::META_ORDER_ROLE => self::ROLE_MAIN,
                // The mark the orders-list column renders (WC has no native tags).
                WooOrderTags::META_TAGS => WooOrderTags::line(WooOrderTags::KIND_INSTALLMENTS),
                self::META_INSTALLMENT_STATUS => 'active',
                self::META_PAID_AMOUNT => $this->money((float) $plan->total_charged),
                self::META_REMAINING_BALANCE => $this->money($plan->remainingAmount()),
                self::META_NEXT_CHARGE_AT => $plan->next_charge_at?->toIso8601String() ?? '',
            ]),
        ]);

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            return; // WC returned no id; the ledger still holds the money truth
        }

        // Persist the parent order id on the platform-neutral column (forceFill — it is
        // not a state-machine column). Keep the legacy column in sync for read fallbacks.
        $plan->forceFill([
            'external_order_id' => $orderId,
            'shopify_order_id' => $plan->shopify_order_id ?: $orderId,
        ])->save();
    }

    /** installment: update the parent order meta; on the final slice, complete it. */
    private function onInstallment(InstallmentPlan $plan, WooCommerceClient $client, bool $isFinal): void
    {
        $orderId = (string) ($plan->externalOrderId() ?? '');
        if ($orderId === '') {
            return; // no parent order to update (deposit never materialized)
        }

        $completed = $isFinal && $plan->isFullyPaid();

        $payload = [
            'meta_data' => $this->meta([
                self::META_PAID_AMOUNT => $this->money((float) $plan->total_charged),
                self::META_REMAINING_BALANCE => $this->money($plan->remainingAmount()),
                self::META_NEXT_CHARGE_AT => $plan->next_charge_at?->toIso8601String() ?? '',
                self::META_INSTALLMENT_STATUS => $completed ? 'completed' : 'active',
            ]),
        ];

        // Final slice releases fulfillment: flip the parent order to completed.
        if ($completed) {
            $payload['status'] = self::STATUS_COMPLETED;
        }

        $client->updateOrder($orderId, $payload);
    }

    /** recurring: a NEW PAID WC order per cycle, linked by meta (failed cycle ⇒ no order). */
    private function onRecurring(InstallmentPlan $plan, WooCommerceClient $client): void
    {
        // A ONE-TIME next-order override (W25) shapes THIS cycle's LINE ITEMS; the
        // AMOUNT comes from the shared resolver (the succeeded slot's frozen amount →
        // override → the plan ladder) so the order always sums to the money that moved,
        // including a cycle priced past the intro-discount window.
        $override = $plan->nextOrderOverride();
        $amount = (new CycleAmountResolver)->amountForCycleOrder($plan);
        if ($amount <= 0) {
            return;
        }

        $lineItems = $override !== null
            ? $this->overrideLineItems($override, $plan)
            : [$this->recurringLineItem($plan, $amount)];

        // Discount-tag predicate: the cycle charged BELOW the plan's regular
        // (undiscounted) price — intro window active, or a kept first payment.
        $discounted = $plan->regular_amount !== null
            && $amount < round((float) $plan->regular_amount, 2);

        $customerId = $this->customerIdFor($plan, $client);
        // Read LIVE, every cycle: the address a shopper edits in My Account is
        // the address their next box must go to.
        $address = WooOrderAddress::forOrder($plan, $plan->shop, $customerId);

        $order = $client->createOrder([
            'status' => self::STATUS_COMPLETED,         // a fulfillable, paid cycle order
            'set_paid' => true,                         // the money already moved through PayPlus
            'currency' => (string) $plan->currency,
            'customer_id' => $customerId,
            'billing' => $address['billing'],
            'shipping' => $address['shipping'],
            'line_items' => $lineItems,
            'meta_data' => $this->meta([
                self::META_PLAN_PUBLIC_ID => (string) $plan->public_id,
                self::META_ORDER_ROLE => self::ROLE_RECURRING,
                WooOrderTags::META_TAGS => $discounted
                    ? WooOrderTags::line(WooOrderTags::KIND_RECURRING, WooOrderTags::KIND_DISCOUNT)
                    : WooOrderTags::line(WooOrderTags::KIND_RECURRING),
                self::META_MAIN_ORDER_ID => (string) ($plan->externalOrderId() ?? ''),
                self::META_PAID_AMOUNT => $this->money($amount),
            ]),
        ]);

        // Record the cycle order id in plan meta (idempotency + reconciliation trail).
        $orderId = (string) ($order['id'] ?? '');
        if ($orderId !== '') {
            $meta = (array) ($plan->meta ?? []);
            $ids = (array) ($meta[self::META_RECURRING_ORDER_IDS] ?? []);
            $ids[] = $orderId;
            $meta[self::META_RECURRING_ORDER_IDS] = array_values(array_unique($ids));
            $plan->forceFill(['meta' => $meta])->save();
        }
    }

    /**
     * WC line items from a W25 one-time next-order override. Each row links the real product (numeric
     * WC id) when known so WC decrements stock + shows the product; `total` pins the server-computed
     * line total (qty × unit price) so the order sums to the charged amount. We store only product_id
     * (never a variation_id echoing a simple product's id — the W23 bogus-variation trap). An empty
     * list degrades to a single named line at the override amount, never a zero-line order.
     *
     * @param  array<string, mixed>  $override
     * @return list<array<string, mixed>>
     */
    private function overrideLineItems(array $override, InstallmentPlan $plan): array
    {
        $lines = [];
        foreach ((array) ($override['line_items'] ?? []) as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unit = round((float) ($item['unit_price'] ?? 0), 2);
            $line = [
                'name' => (string) ($item['name'] ?? __('storefront.installments.recurring_line', ['plan' => (string) $plan->public_id])),
                'quantity' => $qty,
                'total' => $this->money(round($unit * $qty, 2)),
            ];
            if (($pid = (int) ($item['product_id'] ?? 0)) > 0) {
                $line['product_id'] = $pid;
            }
            $lines[] = $line;
        }

        return $lines !== [] ? $lines : [[
            'name' => __('storefront.installments.recurring_line', ['plan' => (string) $plan->public_id]),
            'quantity' => 1,
            'total' => $this->money(round((float) ($override['amount'] ?? 0), 2)),
        ]];
    }

    /** retry/manual fall through to the plan's underlying kind. */
    private function onRecurringOrInstallment(InstallmentPlan $plan, WooCommerceClient $client, bool $isFinal): void
    {
        if ($plan->isRecurring()) {
            $this->onRecurring($plan, $client);

            return;
        }
        $this->onInstallment($plan, $client, $isFinal);
    }

    /**
     * upsell: handled OUT-OF-BAND by App\Domain\Upsell\UpsellChargeService (a linked
     * child order), NOT through this plan-keyed strategy — an upsell is a charge context,
     * not a plan. Defensive no-op so a mis-routed upsell never creates a duplicate order.
     */
    private function onUpsell(InstallmentPlan $plan): void
    {
        Log::info('woocommerce.order_strategy.upsell_handled_by_charge_service', ['plan_id' => $plan->getKey()]);
    }

    // === Payload builders ===

    /** The parent installments line item, priced at the FULL product total. */
    /**
     * The cycle's single line. The REAL product reference leads and the free-text
     * name is only the fallback — modern WooCommerce (the stores that matter)
     * REFUSES a line with neither product_id nor sku
     * (`woocommerce_rest_required_product_reference`), which is exactly how every
     * recurring cycle on the pilot store silently produced NO order: the strategy
     * sent a name-only line, the store said 400, and the orchestrator's fail-soft
     * swallow was the only witness. The reference also makes the order READ right:
     * the merchant sees the actual product, and WC decrements its stock.
     *
     * @return array<string, mixed>
     */
    private function recurringLineItem(InstallmentPlan $plan, float $amount): array
    {
        $line = [
            'quantity' => 1,
            // Pinned server-side so the order sums to the money that MOVED, even
            // when the product reference would price it differently today.
            'total' => $this->money($amount),
        ];

        $productId = $this->numericOrNull($plan->external_product_id ?: $plan->shopify_product_id);
        $variantId = $this->numericOrNull($plan->external_variant_id ?: $plan->shopify_variant_id);

        if ($productId !== null) {
            $line['product_id'] = $productId;
        }
        // Never a variation_id echoing a simple product's own id (the W23 trap).
        if ($variantId !== null && $variantId !== $productId) {
            $line['variation_id'] = $variantId;
        }
        if ($productId === null && $variantId === null) {
            // No catalog reference on the plan (some imports). A name-only line is
            // refused by newer stores — sent anyway as the only honest option, and
            // the failure now lands on the Timeline instead of dying in a log.
            $line['name'] = __('storefront.installments.recurring_line', ['plan' => (string) $plan->public_id]);
        }

        return $line;
    }

    private function mainLineItem(InstallmentPlan $plan): array
    {
        $line = [
            'quantity' => 1,
            'total' => $this->money((float) $plan->total_amount),
        ];

        // Prefer the WC product/variation ids when present (so WC links to the real
        // catalog item); fall back to a free-text line (name) when they are absent.
        $productId = $this->numericOrNull($plan->external_product_id ?: $plan->shopify_product_id);
        $variantId = $this->numericOrNull($plan->external_variant_id ?: $plan->shopify_variant_id);

        if ($productId !== null) {
            $line['product_id'] = $productId;
        }
        if ($variantId !== null) {
            $line['variation_id'] = $variantId;
        }
        if ($productId === null && $variantId === null) {
            $line['name'] = __('storefront.installments.deposit_note', ['plan' => (string) $plan->public_id]);
        }

        return $line;
    }

    /**
     * Map an associative key=>value bag onto WC's meta_data [{key,value}] shape.
     *
     * @param  array<string, mixed>  $pairs
     * @return list<array{key:string, value:mixed}>
     */
    private function meta(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $key => $value) {
            $out[] = ['key' => $key, 'value' => $value];
        }

        return $out;
    }

    /** WC wants money as a 2dp string (e.g. "100.00"). */
    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    /** A positive integer id, or null (so we never post variation_id=0 to WC). */
    private function numericOrNull(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** WHICH customer this order belongs to — see WooCustomerResolver. */
    private function customerIdFor(InstallmentPlan $plan, WooCommerceClient $client): int
    {
        return WooCustomerResolver::resolve($plan, $client);
    }
}
