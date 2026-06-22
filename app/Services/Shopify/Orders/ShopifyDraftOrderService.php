<?php

namespace App\Services\Shopify\Orders;

use App\Models\InstallmentPlan;
use App\Services\Shopify\ShopifyAdminApi;

/**
 * The draft-order pattern, both directions. Ported (lean) + multi-tenant from the
 * reference ShopifyDraftOrderService. TWO locked Shopify shapes:
 *
 *   1. UPSELL child order  — draft COMPLETED-as-paid (createUpsellChildOrder*):
 *      the money already moved on the saved PayPlus token, so we complete the draft
 *      as paid, yielding a separate linked child order — no order-edit, no
 *      external-payment reconciliation issues.
 *
 *   2. DEPOSIT invoice (W9 Part C) — draft left OPEN (createDepositInvoice): the
 *      installments-plan first payment. We create an UNPAID deposit draft and hand
 *      back its hosted invoiceUrl; the customer pays it on PayPlus, and the
 *      orders/paid webhook then activates the plan (PlanActivationService).
 *
 * @see DefaultShopifyOrderStrategy::onUpsell()             — the upsell call site.
 * @see App\Domain\Installments\Http\Controllers\Storefront\StartInstallmentPlanController — the deposit call site.
 */
final class ShopifyDraftOrderService
{
    // === CONSTANTS ===
    /** Note/custom attribute keys that link the deposit draft+order back to the plan. */
    private const ATTR_PLAN_PUBLIC_ID = 'pps_plan_public_id';
    private const ATTR_ORDER_ROLE = 'pps_order_role';
    private const ROLE_DEPOSIT = 'installments_deposit';

    public function __construct(private readonly ShopifyAdminApi $client) {}

    /**
     * Create the UNPAID deposit draft order for an installments plan and return the
     * hosted invoice URL the customer is redirected to to pay the deposit.
     *
     * The DEPOSIT case (W9 Part C) — the mirror image of the upsell child order:
     *   - upsell  = draft COMPLETED-as-paid (money already moved on the saved token);
     *   - deposit = draft left OPEN (the customer is about to pay it on PayPlus).
     * We never complete the draft here; orders/paid activates the plan once the
     * deposit is paid. The line price is the SERVER-computed deposit amount the
     * controller passed (originalUnitPrice), never a client-sent value — money law.
     *
     * The draft + the resulting order carry custom attributes that link back to the
     * plan (public_id + role), so the orders/paid handler can find the plan by the
     * draft id OR by these note attributes and activate it.
     *
     * @param  array{title: string, deposit_amount: float, quantity?: int, variant_gid?: string}  $lineItem
     * @return array{draft_order_id: string, draft_order_gid: string, invoice_url: string, name: string}
     */
    public function createDepositInvoice(InstallmentPlan $plan, array $lineItem): array
    {
        // The draft inherits the shop's store currency (Israeli PayPlus merchants =
        // ILS); we don't pin presentmentCurrencyCode so multi-currency stores keep
        // their own resolution. The amount is the server-computed deposit.
        $email = (string) ($plan->customer_email ?? '');

        // GraphQL DraftOrderInput line item. We send a CUSTOM line item (title +
        // explicit price, NO variantId) so the invoice charges EXACTLY the deposit,
        // not the variant's full retail price. The price field is the Money scalar
        // `originalUnitPrice` (the deposit amount, server-trusted).
        //
        // API-VERSION NOTE (verify before a version bump — §11): on DraftOrderLineItemInput,
        // `originalUnitPrice` (Money string) is the deposit price field; recent
        // versions also expose `originalUnitPriceWithCurrency` (MoneyInput). Pinned
        // to 2026-04 where `originalUnitPrice` is accepted. If a future version
        // removes it, switch to originalUnitPriceWithCurrency:{amount,currencyCode}.
        $lineInput = array_filter([
            'title' => (string) $lineItem['title'],
            'quantity' => (int) ($lineItem['quantity'] ?? 1),
            'originalUnitPrice' => number_format((float) $lineItem['deposit_amount'], 2, '.', ''),
            'requiresShipping' => false,
        ], static fn ($v): bool => $v !== null);

        $input = array_filter([
            'email' => $email !== '' ? $email : null,
            'tags' => [(string) (config('shopify.tags.installments_hold') ?? 'installments-hold')],
            'lineItems' => [$lineInput],
            'customAttributes' => [
                ['key' => self::ATTR_ORDER_ROLE, 'value' => self::ROLE_DEPOSIT],
                ['key' => self::ATTR_PLAN_PUBLIC_ID, 'value' => (string) $plan->public_id],
            ],
            'note' => __('storefront.installments.deposit_note', ['plan' => (string) $plan->public_id]),
        ], static fn ($v): bool => $v !== null && $v !== '');

        return $this->client->createDepositDraftOrder($input);
    }

    /**
     * Create a linked child order for an upsell, as a completed-as-paid draft.
     *
     * @param  array{title: string, price: float, quantity?: int}  $lineItem
     * @return array{shopify_order_id: string, shopify_order_gid: ?string}
     */
    public function createUpsellChildOrder(InstallmentPlan $plan, string $parentOrderId, array $lineItem): array
    {
        $tags = (array) config('shopify.tags');

        $draft = $this->client->createDraftOrder([
            'email' => (string) $plan->customer_email,
            'currency' => (string) $plan->currency,
            'tags' => (string) ($tags['upsell_child'] ?? 'upsell-child'),
            'note_attributes' => [
                ['name' => 'pps_main_order_id', 'value' => $parentOrderId],
                ['name' => 'pps_order_role', 'value' => 'upsell_child'],
                ['name' => 'pps_plan_public_id', 'value' => (string) $plan->public_id],
            ],
            'line_items' => [[
                'title' => (string) $lineItem['title'],
                'price' => number_format((float) $lineItem['price'], 2, '.', ''),
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
            ]],
        ]);

        $draftId = (string) ($draft['id'] ?? '');

        // Complete as PAID (payment_pending=false): the money already moved on the
        // saved token via laravel-backend; this just records the paid child order.
        $order = $this->client->completeDraftOrder($draftId, paymentPending: false);

        return [
            'shopify_order_id' => (string) ($order['order_id'] ?? $order['id'] ?? ''),
            'shopify_order_gid' => (string) ($order['admin_graphql_api_id'] ?? '') ?: null,
        ];
    }

    /**
     * PLAN-LESS variant for the Phase-6 post-purchase upsell. The upsell is a
     * charge CONTEXT, not necessarily a plan, so the child order is built from a
     * plain customer + line descriptor (the resolved offer + parent order). Same
     * draft-completed-as-paid shape, same parent linkage attributes, so the
     * merchant sees one linked child order. The money already moved on the saved
     * PayPlus token before this is ever called.
     *
     * @param  array{email?: string, currency?: string}  $customer
     * @param  array{title: string, price: float, quantity?: int, variant_gid?: string}  $lineItem
     * @return array{shopify_order_id: string, shopify_order_gid: ?string}
     */
    public function createUpsellChildOrderForCustomer(string $parentOrderId, array $customer, array $lineItem): array
    {
        $tags = (array) config('shopify.tags');

        $draft = $this->client->createDraftOrder([
            'email' => (string) ($customer['email'] ?? ''),
            'currency' => (string) ($customer['currency'] ?? config('payplus.currency', 'ILS')),
            'tags' => (string) ($tags['upsell_child'] ?? 'upsell-child'),
            'note_attributes' => [
                ['name' => 'pps_main_order_id', 'value' => $parentOrderId],
                ['name' => 'pps_order_role', 'value' => 'upsell_child'],
            ],
            'line_items' => [array_filter([
                'title' => (string) $lineItem['title'],
                'price' => number_format((float) $lineItem['price'], 2, '.', ''),
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
                'variant_id' => $lineItem['variant_gid'] ?? null,
            ], static fn ($v): bool => $v !== null)],
        ]);

        $draftId = (string) ($draft['id'] ?? '');

        $order = $this->client->completeDraftOrder($draftId, paymentPending: false);

        return [
            'shopify_order_id' => (string) ($order['order_id'] ?? $order['id'] ?? ''),
            'shopify_order_gid' => (string) ($order['admin_graphql_api_id'] ?? '') ?: null,
        ];
    }
}
