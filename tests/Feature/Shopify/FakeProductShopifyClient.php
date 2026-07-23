<?php

namespace Tests\Feature\Shopify;

use App\Services\Shopify\ShopifyAdminApi;

/**
 * A Shopify Admin fake that serves CANNED product pages (no HTTP), so the import +
 * webhook tests can drive the source→upserter path deterministically. Implements
 * the full ShopifyAdminApi surface (order methods are inert stubs) and serves the
 * catalog reads from injected data.
 *
 * Pages are keyed by the incoming cursor ('' = first page); each page is the raw
 * `data.products` connection shape the real client returns, so ShopifyProductSource
 * maps them exactly as it would production data.
 */
final class FakeProductShopifyClient implements ShopifyAdminApi
{
    /** @var array<string, array<string, mixed>> cursor-key → connection */
    private array $pages;

    /** @var array<string, array<string, mixed>> gid → product node */
    private array $byGid;

    public int $pageCalls = 0;

    /**
     * @param  array<string, array<string, mixed>>  $pages  cursor-key ('' = first) → connection
     * @param  array<string, array<string, mixed>>  $byGid  gid → product node (for fetchOne)
     */
    public function __construct(array $pages, array $byGid = [])
    {
        $this->pages = $pages;
        $this->byGid = $byGid;
    }

    public function fetchProductsPage(?string $cursor = null, int $first = 50): array
    {
        $this->pageCalls++;
        $key = $cursor ?? '';

        return $this->pages[$key] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    public function fetchProductByGid(string $gid): ?array
    {
        return $this->byGid[$gid] ?? null;
    }

    // === Inert order-strategy stubs (not exercised by catalog tests) ===

    public function graphql(string $query, array $variables = []): array
    {
        return [];
    }

    public function createOrder(array $orderPayload): array
    {
        return [];
    }

    public function createDraftOrder(array $draftPayload): array
    {
        return [];
    }

    public function completeDraftOrder(string $draftId, bool $paymentPending = false): array
    {
        return [];
    }

    public function createDepositDraftOrder(array $input): array
    {
        return [
            'draft_order_gid' => 'gid://shopify/DraftOrder/0',
            'draft_order_id' => '0',
            'invoice_url' => 'https://example.test/invoices/0',
            'name' => '#D0',
        ];
    }

    public function fetchOrderWithMetafields(string $orderId): array
    {
        return [];
    }

    public function fetchOrderFulfillmentOrders(string $orderId): array
    {
        return [];
    }

    public function createFulfillment(array $fulfillmentOrderIds, bool $notifyCustomer = false): array
    {
        return [];
    }

    public function updateOrderTags(string $orderId, array $tags): void {}

    public function setOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array
    {
        return [];
    }

    public function upsertOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array
    {
        return [];
    }

    public function markOrderAsPaid(string $orderGid): array
    {
        return [];
    }

    // === Canned-data builders (keep the test bodies readable) ===

    /**
     * Build a Shopify product node with one variant.
     *
     * @return array<string, mixed>
     */
    public static function productNode(
        string $gid,
        string $title,
        string $status = 'ACTIVE',
        ?string $publishedAt = '2026-01-01T00:00:00Z',
        string $variantGid = '',
        string $sku = 'SKU-1',
        string $price = '49.90',
        array $tags = ['subscription'],
    ): array {
        $variantGid = $variantGid !== '' ? $variantGid : str_replace('/Product/', '/ProductVariant/', $gid).'01';

        return [
            'id' => $gid,
            'title' => $title,
            'handle' => strtolower(str_replace(' ', '-', $title)),
            'status' => $status,
            'publishedAt' => $publishedAt,
            'updatedAt' => '2026-06-01T12:00:00Z',
            'tags' => $tags,
            'featuredImage' => ['url' => 'https://cdn.example.com/'.$title.'.jpg'],
            'variants' => [
                'nodes' => [
                    ['id' => $variantGid, 'title' => 'Default', 'sku' => $sku, 'price' => $price],
                ],
            ],
        ];
    }

    /**
     * Wrap nodes in a `products` connection with optional next cursor.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    public static function connection(array $nodes, ?string $endCursor = null): array
    {
        return [
            'nodes' => $nodes,
            'pageInfo' => [
                'hasNextPage' => $endCursor !== null,
                'endCursor' => $endCursor,
            ],
        ];
    }
}
