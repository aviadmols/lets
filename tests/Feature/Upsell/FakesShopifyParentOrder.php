<?php

namespace Tests\Feature\Upsell;

use App\Services\Shopify\ShopifyClientFactory;
use Tests\Feature\Shopify\RecordingShopifyClient;

/**
 * Scripts Shopify's answer to ShopifyParentOrderVerifier's order lookup, so the
 * offer endpoints can be exercised with no HTTP: "order N exists, belongs to
 * customer C, was placed at T". Call clearShopifyParentOrderFake() in tearDown.
 */
trait FakesShopifyParentOrder
{
    // === CONSTANTS ===
    /** Enough scripted answers for every lookup one test makes. */
    private const SCRIPTED_ORDER_ANSWERS = 10;

    protected ?RecordingShopifyClient $orderLookupClient = null;

    protected function fakeShopifyParentOrder(
        string $orderId,
        ?string $customerId,
        ?string $createdAt = null,
        ?string $cancelledAt = null,
    ): RecordingShopifyClient {
        $answer = ['data' => ['order' => [
            'id' => 'gid://shopify/Order/'.$orderId,
            'createdAt' => $createdAt ?? now()->subMinutes(2)->toIso8601String(),
            'cancelledAt' => $cancelledAt,
            'email' => 'buyer@example.com',
            'customer' => $customerId !== null ? ['id' => 'gid://shopify/Customer/'.$customerId] : null,
        ]]];

        $client = new RecordingShopifyClient();
        $client->graphqlResponses = array_fill(0, self::SCRIPTED_ORDER_ANSWERS, $answer);
        ShopifyClientFactory::fake(fn () => $client);

        return $this->orderLookupClient = $client;
    }

    protected function clearShopifyParentOrderFake(): void
    {
        ShopifyClientFactory::clearFake();
        $this->orderLookupClient = null;
    }
}
