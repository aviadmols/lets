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
            'tags' => ShopifyOrderTags::all('installments_hold'),
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
        $draft = $this->client->createDraftOrder([
            'email' => (string) $plan->customer_email,
            'currency' => (string) $plan->currency,
            'tags' => ShopifyOrderTags::line('upsell_child'),
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
     * The ACCOUNT-OFFER order: a one-time product bought from an offer inside
     * the customer's personal area, charged on the plan's saved PayPlus token.
     * Same draft-completed-as-paid shape as the upsell child, but linked to the
     * SUBSCRIPTION rather than to a parent order (the plan's original order may
     * be a year old), and carrying the account-offer role + ids so the merchant
     * can trace the sale and the loyalty listener can skip it (pps_plan_public_id
     * is the double-accrual tell AccruePointsFromShopifyOrder reads).
     *
     * @param  array{email?: string, currency?: string}  $customer
     * @param  array{title: string, price: float, quantity?: int, variant_gid?: string}  $lineItem
     * @param  array<string, string>  $attributes  extra note attributes (role, offer ids)
     * @return array{shopify_order_id: string, shopify_order_gid: ?string}
     */
    public function createAccountOfferOrder(
        InstallmentPlan $plan,
        array $customer,
        array $lineItem,
        array $attributes = [],
    ): array {
        $noteAttributes = [
            ['name' => self::ATTR_PLAN_PUBLIC_ID, 'value' => (string) $plan->public_id],
        ];
        foreach ($attributes as $name => $value) {
            $noteAttributes[] = ['name' => (string) $name, 'value' => (string) $value];
        }

        // Attach the real store customer when the plan knows them, so the sale
        // lands on their account beside everything else — email alone only
        // matches, an id BELONGS.
        $customerId = $this->restCustomerId((string) ($customer['id'] ?? ''));

        $draft = $this->client->createDraftOrder(array_filter([
            'email' => (string) ($customer['email'] ?? ''),
            'currency' => (string) ($customer['currency'] ?? config('payplus.currency', 'ILS')),
            'customer' => $customerId !== null ? ['id' => $customerId] : null,
            'tags' => ShopifyOrderTags::line('account_offer'),
            'note_attributes' => $noteAttributes,
            'line_items' => [array_filter([
                'title' => (string) $lineItem['title'],
                // The server-computed price the shopper accepted — money law:
                // pinned here so the catalog can never override what was charged.
                'price' => number_format((float) $lineItem['price'], 2, '.', ''),
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
                'variant_id' => $this->restVariantId($lineItem['variant_gid'] ?? null),
            ], static fn ($v): bool => $v !== null)],
        ], static fn ($v): bool => $v !== null));

        $draftId = (string) ($draft['id'] ?? '');

        // This runs AFTER a successful charge: an id-less create must fail LOUD
        // here (a precise reconcile reason) rather than call /draft_orders//….
        if ($draftId === '') {
            throw new \RuntimeException('shopify.draft_order_created_without_id');
        }

        $order = $this->client->completeDraftOrder($draftId, paymentPending: false);

        return [
            'shopify_order_id' => (string) ($order['order_id'] ?? $order['id'] ?? ''),
            'shopify_order_gid' => (string) ($order['admin_graphql_api_id'] ?? '') ?: null,
        ];
    }

    /**
     * The REST draft channel takes NUMERIC ids, never GIDs — a GID here is
     * rejected by Shopify, which on a post-charge path means "charged, no
     * order". Accepts either spelling and hands REST the number, or null for
     * an id that is not one (the line then rides as title+price only).
     */
    private function restVariantId(?string $identifier): ?int
    {
        $identifier = trim((string) $identifier);

        if (str_starts_with($identifier, 'gid://shopify/ProductVariant/')) {
            $identifier = substr($identifier, strlen('gid://shopify/ProductVariant/'));
        }

        return ctype_digit($identifier) && $identifier !== '0' ? (int) $identifier : null;
    }

    /** Same rule for the customer id (an imported member's UUID is not one). */
    private function restCustomerId(string $identifier): ?int
    {
        $identifier = trim($identifier);

        if (str_starts_with($identifier, 'gid://shopify/Customer/')) {
            $identifier = substr($identifier, strlen('gid://shopify/Customer/'));
        }

        return ctype_digit($identifier) && $identifier !== '0' ? (int) $identifier : null;
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
        $draft = $this->client->createDraftOrder([
            'email' => (string) ($customer['email'] ?? ''),
            'currency' => (string) ($customer['currency'] ?? config('payplus.currency', 'ILS')),
            'tags' => ShopifyOrderTags::line('upsell_child'),
            'note_attributes' => [
                ['name' => 'pps_main_order_id', 'value' => $parentOrderId],
                ['name' => 'pps_order_role', 'value' => 'upsell_child'],
            ],
            'line_items' => [array_filter([
                'title' => (string) $lineItem['title'],
                'price' => number_format((float) $lineItem['price'], 2, '.', ''),
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
                // REST takes the NUMBER — a GID would bounce the whole draft.
                'variant_id' => $this->restVariantId($lineItem['variant_gid'] ?? null),
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
