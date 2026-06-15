<?php

namespace Tests\Feature\Shopify;

use App\Services\Shopify\ShopifyAdminApi;

/**
 * A recording fake of the Shopify Admin surface (no HTTP). Lets the order-strategy
 * tests assert the exact payload SHAPE (parent has no transactions; child does)
 * and that release runs markAsPaid + createFulfillment — without a real store.
 */
final class RecordingShopifyClient implements ShopifyAdminApi
{
    /** @var array<int, array<string, mixed>> */
    public array $createdOrders = [];

    /** @var array<int, array{order_id: string, key: string, value: string}> */
    public array $metafields = [];

    /** @var array<int, array<string, mixed>> */
    public array $tagUpdates = [];

    public bool $markedPaid = false;

    public bool $fulfillmentCreated = false;

    private int $nextOrderId = 555000111;

    public function createOrder(array $orderPayload): array
    {
        $this->createdOrders[] = $orderPayload;
        $id = (string) $this->nextOrderId++;

        return ['id' => $id, 'admin_graphql_api_id' => 'gid://shopify/Order/'.$id, 'name' => '#'.$id, 'financial_status' => $orderPayload['financial_status'] ?? 'paid'];
    }

    public function createDraftOrder(array $draftPayload): array
    {
        return ['id' => '777', 'admin_graphql_api_id' => 'gid://shopify/DraftOrder/777'];
    }

    public function completeDraftOrder(string $draftId, bool $paymentPending = false): array
    {
        return ['id' => '888', 'order_id' => '888', 'admin_graphql_api_id' => 'gid://shopify/Order/888'];
    }

    public function fetchOrderWithMetafields(string $orderId): array
    {
        return ['id' => $orderId, 'tags' => 'installments-hold, installment_plan_active', 'metafields' => []];
    }

    public function fetchOrderFulfillmentOrders(string $orderId): array
    {
        return [['id' => 4242]];
    }

    public function createFulfillment(array $fulfillmentOrderIds, bool $notifyCustomer = false): array
    {
        $this->fulfillmentCreated = true;

        return ['id' => '9999', 'status' => 'success'];
    }

    public function updateOrderTags(string $orderId, array $tags): void
    {
        $this->tagUpdates[] = ['order_id' => $orderId, 'tags' => $tags];
    }

    public function setOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array
    {
        $this->metafields[] = ['order_id' => $orderId, 'key' => $key, 'value' => $value];

        return ['id' => count($this->metafields), 'namespace' => $namespace, 'key' => $key, 'value' => $value];
    }

    public function upsertOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array
    {
        return $this->setOrderMetafield($orderId, $namespace, $key, $value, $type);
    }

    public function markOrderAsPaid(string $orderGid): array
    {
        $this->markedPaid = true;

        return ['order' => ['id' => $orderGid, 'displayFinancialStatus' => 'PAID']];
    }

    public function fetchProductsPage(?string $cursor = null, int $first = 50): array
    {
        return ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    public function fetchProductByGid(string $gid): ?array
    {
        return null;
    }
}
