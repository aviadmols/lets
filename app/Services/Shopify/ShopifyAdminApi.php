<?php

namespace App\Services\Shopify;

/**
 * The Shopify Admin surface the order services depend on. Extracted so order
 * strategies type-hint a CONTRACT, not the concrete HTTP client — which lets
 * tests inject a recording fake (no HTTP) and keeps the boundary swappable.
 * ShopifyAdminClient is the production implementation (per-shop, rate-limited).
 *
 * Only the methods the order strategy + fulfillment-lock services actually call
 * are declared here; the concrete client carries a wider REST/GraphQL surface for
 * sync + diagnostics.
 */
interface ShopifyAdminApi
{
    /**
     * One raw GraphQL call (query or mutation) with variables, returning the
     * decoded body. Promoted onto the contract by the Shopify-Payments
     * subscriptions rail — its services (ContractActionService, SellingPlanService,
     * BillingAttemptJob) run mutations no REST-ish wrapper exists for, and typing
     * against the interface is what lets tests fake them without HTTP.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array;

    public function createOrder(array $orderPayload): array;

    public function createDraftOrder(array $draftPayload): array;

    public function completeDraftOrder(string $draftId, bool $paymentPending = false): array;

    /**
     * Create an UNPAID deposit draft order via GraphQL draftOrderCreate and return
     * the hosted invoice URL the customer pays at. Unlike createDraftOrder (REST,
     * no URL) this is the GraphQL surface specifically because only the GraphQL
     * DraftOrder node exposes `invoiceUrl`. The draft stays OPEN (not completed) —
     * the customer pays it on PayPlus, and orders/paid then activates the plan.
     *
     * @param  array<string, mixed>  $input  GraphQL DraftOrderInput (lineItems, email, tags, customAttributes, …)
     * @return array{draft_order_id: string, draft_order_gid: string, invoice_url: string, name: string}
     */
    public function createDepositDraftOrder(array $input): array;

    public function fetchOrderWithMetafields(string $orderId): array;

    /** @return array<int, array<string, mixed>> */
    public function fetchOrderFulfillmentOrders(string $orderId): array;

    /** @param array<int, int|string> $fulfillmentOrderIds */
    public function createFulfillment(array $fulfillmentOrderIds, bool $notifyCustomer = false): array;

    public function updateOrderTags(string $orderId, array $tags): void;

    public function setOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array;

    public function upsertOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array;

    public function markOrderAsPaid(string $orderGid): array;

    // === Catalog reads (thin transport for ShopifyProductSource) ===

    /**
     * Raw decoded GraphQL `data.products` connection for one page (nodes +
     * pageInfo). The SOURCE owns GID→DTO mapping; the client stays thin — it only
     * runs the cost-aware query and hands back the decoded connection.
     *
     * @return array<string, mixed>  the `products` connection ({nodes, pageInfo}) or []
     */
    public function fetchProductsPage(?string $cursor = null, int $first = 50): array;

    /**
     * Raw decoded GraphQL `product` node for one product GID, or null if absent.
     *
     * @return array<string, mixed>|null
     */
    public function fetchProductByGid(string $gid): ?array;
}
