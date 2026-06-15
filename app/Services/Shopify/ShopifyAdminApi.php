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
    public function createOrder(array $orderPayload): array;

    public function createDraftOrder(array $draftPayload): array;

    public function completeDraftOrder(string $draftId, bool $paymentPending = false): array;

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
