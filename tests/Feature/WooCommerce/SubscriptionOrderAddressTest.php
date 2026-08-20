<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * A subscription order has to know where the box goes.
 *
 * Orders LETS created carried an email, a name and a phone and NO address, so a
 * subscription box arrived in WooCommerce with nowhere to send it and the
 * merchant addressed it by hand every cycle.
 *
 * The store is the authority and it is read LIVE, every cycle: the address a
 * shopper edits in My Account tonight is the address their next box goes to,
 * with no sync and no second copy to drift. An imported member with no store
 * account falls back to the address their import carried.
 */
final class SubscriptionOrderAddressTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_shoppers_current_store_address_is_where_the_box_goes(): void
    {
        $shop = $this->store('addr-live.example.com', customerId: 4, customer: [
            'id' => 4,
            'first_name' => 'דנה',
            'last_name' => 'לוי',
            'email' => 'dana@example.com',
            // What they changed in My Account this morning.
            'shipping' => [
                'first_name' => 'דנה', 'last_name' => 'לוי',
                'address_1' => 'הרצל 12', 'address_2' => 'דירה 4',
                'city' => 'תל אביב', 'postcode' => '6120101', 'country' => 'IL',
            ],
            'billing' => ['phone' => '050-1234567', 'address_1' => 'כתובת ישנה', 'city' => 'חיפה'],
        ]);

        $order = $this->cycleOrder($shop, ['external_customer_id' => '4', 'shopify_customer_id' => '4']);

        // SHIPPING, not billing: the reader prefers where a package goes.
        $this->assertSame('הרצל 12', $order['shipping']['address_1']);
        $this->assertSame('דירה 4', $order['shipping']['address_2']);
        $this->assertSame('תל אביב', $order['shipping']['city']);
        $this->assertSame('6120101', $order['shipping']['postcode']);
        $this->assertSame('IL', $order['shipping']['country']);

        // The billing block carries the address too, plus the contact details the
        // CHARGE was made against.
        $this->assertSame('הרצל 12', $order['billing']['address_1']);
        $this->assertSame(self::MEMBER_EMAIL, $order['billing']['email']);
    }

    /**
     * An imported member often has no store account at all. Without their own
     * stored address, their very first renewal would ship nowhere.
     */
    public function test_an_imported_members_own_address_is_used_when_the_store_has_none(): void
    {
        $shop = $this->store('addr-import.example.com', customerId: null);

        $order = $this->cycleOrder($shop, [
            // The imported shape: a UUID reference, no store account.
            'external_customer_id' => 'b3f1c0de-0000-4000-8000-000000000001',
            'shopify_customer_id' => 'b3f1c0de-0000-4000-8000-000000000001',
            'customer_phone' => '0509999999',
            'meta' => ['import' => ['address' => [
                'street' => 'ביאליק',
                'building_number' => '7',
                'apartment_number' => '3',
                'city' => 'רמת גן',
                'zip_code' => '5252525',
                'country' => 'IL',
            ]]],
        ]);

        // The import's vocabulary mapped onto a real Woo block.
        $this->assertSame('ביאליק 7', $order['shipping']['address_1']);
        $this->assertSame('דירה 3', $order['shipping']['address_2']);
        $this->assertSame('רמת גן', $order['shipping']['city']);
        $this->assertSame('5252525', $order['shipping']['postcode']);
    }

    /**
     * A half-filled import — a city and nothing else — is not an address. Sending
     * it would look like a delivery instruction the store cannot fulfil.
     */
    public function test_a_partial_address_is_not_sent_as_one(): void
    {
        $shop = $this->store('addr-partial.example.com', customerId: null);

        $order = $this->cycleOrder($shop, [
            'external_customer_id' => '0',
            'shopify_customer_id' => '0',
            'meta' => ['import' => ['address' => ['city' => 'רמת גן']]],
        ]);

        $this->assertSame([], $order['shipping']);
        // The order still exists, still addressed to the right person.
        $this->assertSame(self::MEMBER_EMAIL, $order['billing']['email']);
    }

    // === Helpers ===

    /** @param array<string, mixed> $planAttributes @return array<string, mixed> */
    private function cycleOrder(Shop $shop, array $planAttributes): array
    {
        $plan = Tenant::run($shop, fn () => $this->makeSourcePlan($planAttributes));

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy)
            ->materialize($plan, ChargeContext::RECURRING, false));

        $this->assertNotEmpty($this->orders, 'no WC order was created');

        return (array) end($this->orders);
    }

    /** @param array<string, mixed>|null $customer */
    private function store(string $domain, ?int $customerId, ?array $customer = null): Shop
    {
        $shop = $this->makeShop($domain);
        $shop->forceFill([
            'woocommerce_domain' => 'wc.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://wc.example.com',
                'consumer_key' => 'ck_x',
                'consumer_secret' => 'cs_x',
            ],
        ])->save();

        $test = $this;
        Http::fake([
            // The by-email lookup that resolves WHICH customer this is.
            '*/wp-json/wc/v3/customers?*' => Http::response(
                $customerId === null ? [] : [['id' => $customerId]],
                200
            ),
            // …and the read of that customer's current address.
            '*/wp-json/wc/v3/customers/*' => Http::response($customer ?? [], 200),
            '*/wp-json/wc/v3/orders' => function ($request) use ($test) {
                $test->orders[] = (array) $request->data();

                return Http::response(['id' => 7000 + count($test->orders)], 201);
            },
            '*' => Http::response([], 200),
        ]);

        return $shop->refresh();
    }
}
