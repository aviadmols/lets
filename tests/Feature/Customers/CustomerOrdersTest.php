<?php

namespace Tests\Feature\Customers;

use App\Domain\Customers\CustomerContact;
use App\Domain\Customers\CustomerOrdersReader;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooOrderTags;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A customer's order history, with the LETS-created ones marked.
 *
 * ALL of their orders — the merchant is asking what this person has bought, and
 * showing only our slice would present a fragment as the whole history.
 *
 * "Created by LETS" is answered two ways because the store-side mark only exists
 * on orders created since tagging shipped. An older subscription cycle is still
 * ours, and calling it a plain sale would be wrong about the merchant's numbers.
 */
final class CustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'orders.example.com',
            'name' => 'Orders',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $this->shop->woocommerce_credentials = [
            'base_url' => 'https://orders.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $this->shop->save();
        $this->shop = $this->shop->fresh();
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_every_order_is_listed_and_ours_is_marked(): void
    {
        $this->fakeOrders([
            $this->order(101, '120.00', tagged: false),
            $this->order(102, '0.00', tagged: true),
        ]);

        $result = app(CustomerOrdersReader::class)->read($this->shop, '55');

        $this->assertCount(2, $result['orders']);
        $this->assertFalse($result['orders'][0]['from_lets']);
        $this->assertTrue($result['orders'][1]['from_lets']);
        $this->assertSame(120.0, $result['orders'][0]['total']);
    }

    public function test_an_order_we_created_before_tagging_is_still_recognised(): void
    {
        // No meta on the order — but our ledger says we charged it.
        $this->fakeOrders([$this->order(103, '50.00', tagged: false)]);
        $this->ledgerFor('103');

        $result = app(CustomerOrdersReader::class)->read($this->shop, '55');

        $this->assertTrue($result['orders'][0]['from_lets']);
    }

    public function test_a_guest_has_no_linked_order_history(): void
    {
        Http::fake();

        $result = app(CustomerOrdersReader::class)->read($this->shop, 'guest@example.com');

        $this->assertSame([], $result['orders']);
        $this->assertSame(CustomerContact::REASON_GUEST, $result['reason']);
        Http::assertNothingSent();
    }

    public function test_an_unreachable_store_leaves_the_panel_empty_not_broken(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = app(CustomerOrdersReader::class)->read($this->shop, '55');

        $this->assertSame([], $result['orders']);
        $this->assertSame(CustomerContact::REASON_UNAVAILABLE, $result['reason']);
    }

    // === Fixtures ===

    /** @param array<int, array<string, mixed>> $orders */
    private function fakeOrders(array $orders): void
    {
        Http::fake(['*/wp-json/wc/v3/orders*' => Http::response($orders, 200)]);
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $total, bool $tagged): array
    {
        return [
            'id' => $id,
            'number' => (string) $id,
            'date_created' => '2026-07-01T10:00:00',
            'total' => $total,
            'currency' => 'ILS',
            'status' => 'processing',
            'meta_data' => $tagged
                ? [['key' => WooOrderTags::META_TAGS, 'value' => 'LETS, gift']]
                : [],
        ];
    }

    private function ledgerFor(string $orderId): void
    {
        $ledger = new PaymentLedger;
        $ledger->forceFill([
            'shop_id' => $this->shop->getKey(),
            'idempotency_key' => (string) Str::ulid(),
            'charge_context' => 'recurring',
            'amount' => 50,
            'currency' => 'ILS',
            'status' => PaymentLedger::STATUS_SUCCEEDED,
            'shopify_customer_id' => '55',
            'shopify_order_id' => $orderId,
        ])->save();
    }
}
