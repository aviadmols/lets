<?php

namespace Tests\Feature\Refunds;

use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Domain\Refunds\ShopifyStoreRefunder;
use App\Domain\Refunds\StoreRefunderFactory;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Telling Shopify that money went back — without letting Shopify send it again.
 *
 * `refundCreate` takes transactions, and each one either MOVES money or merely
 * records it depending on the gateway named. An order this app charged was
 * marked paid through Shopify's MANUAL gateway while the real money went through
 * PayPlus — and PayPlus has already returned it by the time this runs. Naming
 * the order's real transaction here would refund the shopper twice, with neither
 * system saying so. That is the single assertion this file exists for.
 */
final class ShopifyStoreRefunderTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    private RecordingShopifyClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new RecordingShopifyClient;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $this->client);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        $this->clearRefundFakes();
        parent::tearDown();
    }

    public function test_the_refund_transaction_records_money_it_does_not_move_it(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '6001', amount: 120.00, uid: 'txn-sale');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '6001']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);

        $input = $this->refundInput();

        $this->assertSame('gid://shopify/Order/6001', $input['orderId']);
        $this->assertCount(1, $input['transactions']);
        $this->assertSame('REFUND', $input['transactions'][0]['kind']);
        $this->assertSame('120.00', $input['transactions'][0]['amount']);
        $this->assertSame(
            config('shopify.order_tx_gateway', 'manual'),
            $input['transactions'][0]['gateway'],
            'The manual gateway records the refund; the real money already went back through PayPlus.',
        );
        $this->assertArrayNotHasKey(
            'parentId',
            $input['transactions'][0],
            'Naming the sale transaction would make Shopify refund it a second time.',
        );
    }

    public function test_a_partial_refund_records_only_what_went_back(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '6002', amount: 120.00, uid: 'txn-sale');

        $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'order_id' => '6002',
            'amount' => 45.00,
        ]);

        $this->assertSame('45.00', $this->refundInput()['transactions'][0]['amount']);
    }

    public function test_cancelling_asks_shopify_to_move_no_money(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '6003', amount: 100.00, uid: 'txn-sale');

        $this->refund($shop, [
            'mode' => RefundRequest::MODE_CANCEL_ORDER,
            'order_id' => '6003',
            'restock' => true,
        ]);

        $cancel = $this->graphqlCall('orderCancel');

        $this->assertNotNull($cancel, 'The order is cancelled on the store.');
        $this->assertFalse($cancel['variables']['refund'], 'The money side was already recorded.');
        $this->assertFalse(
            $cancel['variables']['restock'],
            'The refund above already restocked; asking twice returns the same stock twice.',
        );
    }

    public function test_the_marker_metafield_names_the_request(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '6004', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '6004']);

        $this->assertContains(
            ShopifyStoreRefunder::MARKER_KEY_PREFIX.$request->getKey(),
            array_column($this->client->metafields, 'key'),
        );
    }

    /**
     * An amount-based refund has no basket, so there is nothing to restock —
     * Shopify has no "return proportionally", and inventing quantities would put
     * back stock the merchant never said to return.
     */
    public function test_an_amount_only_refund_restocks_nothing(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '6005', amount: 100.00, uid: 'txn-sale');

        $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'order_id' => '6005',
            'amount' => 25.00,
            'restock' => true,
        ]);

        $this->assertArrayNotHasKey('refundLineItems', $this->refundInput());
    }

    public function test_shopify_refusing_leaves_the_refund_needing_attention(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->client->graphqlThrows = new \RuntimeException('shopify.graphql_errors: order is archived');

        $this->makeCharge($shop, orderId: '6006', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '6006']);

        $this->assertSame(RefundRequest::STATUS_NEEDS_ATTENTION, $request->status);
        $this->assertSame('store_exception', $request->store_result['error']);
        $this->assertSame(100.00, $request->refundedTotal(), 'The shopper still has their money.');
    }

    /**
     * A scope this app has not been approved for is named specifically — it is a
     * Partner Dashboard action the merchant can take, not a fault they can only
     * report to us.
     */
    public function test_a_protected_data_refusal_is_named_as_such(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $this->client->graphqlThrows = new \RuntimeException(
            'shopify.graphql_errors: This app is not approved to use the read_orders scope.',
        );

        $this->makeCharge($shop, orderId: '6007', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '6007']);

        $this->assertSame('protected_data_pending', $request->store_result['error']);
    }

    // === Helpers ===

    /** @return array<string, mixed> the input handed to refundCreate */
    private function refundInput(): array
    {
        $call = $this->graphqlCall('refundCreate');

        $this->assertNotNull($call, 'refundCreate was never called.');

        return (array) $call['variables']['input'];
    }

    /** @return array{query: string, variables: array<string, mixed>}|null */
    private function graphqlCall(string $mutation): ?array
    {
        foreach ($this->client->graphqlCalls as $call) {
            if (str_contains($call['query'], $mutation.'(')) {
                return $call;
            }
        }

        return null;
    }

    private function shopifyShop(): Shop
    {
        // The real refunder, not the recording fake — this file is about the
        // payload Shopify actually receives.
        StoreRefunderFactory::clearFake();

        $shop = Shop::create([
            'shopify_domain' => 'refunds.myshopify.com',
            'name' => 'Refunds Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk',
            'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ];
        $shop->shopify_access_token = 'shpat_test';
        $shop->save();

        return $shop->fresh();
    }

    /** @param array<string, mixed> $input */
    private function refund(Shop $shop, array $input): RefundRequest
    {
        $orchestrator = app(RefundOrchestrator::class);

        return $orchestrator->run($shop, $orchestrator->open($shop, $input));
    }
}
