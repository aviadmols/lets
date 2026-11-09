<?php

namespace Tests\Feature\Customers;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Filament\Pages\CustomerDetail;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\WooCommerce\WooOrderAddress;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The house, apartment, floor and entrance reach the order and the screen.
 *
 * A store customer profile keeps the street ALONE in address_1 and the parts in
 * the LETS address fields of their own. LETS read those fields, then built orders
 * and the customer page from address_1/address_2 only — a renewal box addressed to
 * a street with no house number, and a contact card without one.
 */
final class AddressPartsInOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_profile_shaped_address_composes_its_parts_into_the_order_lines(): void
    {
        $address = GiftShippingAddress::fromWooPayload($this->profile());

        $this->assertSame([
            'address1' => 'אירוסים 82',
            'address2' => 'דירה 4, קומה 2, כניסה ב',
        ], $address?->orderLines());

        $this->assertSame('אירוסים 82', $address?->toWooOrderBlock()['address_1']);
        $this->assertSame('דירה 4, קומה 2, כניסה ב', $address?->toWooOrderBlock()['address_2']);
    }

    public function test_the_block_written_back_to_a_profile_keeps_the_stores_own_shape(): void
    {
        // CustomerContactWriter writes this one: the house stays in its own field,
        // so composing it into address_1 here would double it on the next checkout.
        $block = GiftShippingAddress::fromWooPayload($this->profile())?->toWooBlock();

        $this->assertSame('אירוסים', $block['address_1']);
        $this->assertArrayNotHasKey('address_2', $block);
    }

    public function test_lines_that_already_carry_the_parts_come_back_unchanged(): void
    {
        // A checkout order, as the plugin composes it — and its parts as meta.
        $address = GiftShippingAddress::fromWooPayload([
            'billing' => [
                'address_1' => 'אליהו הנביא 18',
                'address_2' => 'דירה 4, קומה 2, כניסה ב',
                'city' => 'חיפה',
                'country' => 'IL',
            ],
            'meta_data' => [
                ['key' => 'billing_building_number', 'value' => '18'],
                ['key' => 'billing_apartment_number', 'value' => '4'],
            ],
        ]);

        $this->assertSame([
            'address1' => 'אליהו הנביא 18',
            'address2' => 'דירה 4, קומה 2, כניסה ב',
        ], $address?->orderLines());
    }

    public function test_address_2_text_that_is_none_of_the_parts_is_kept(): void
    {
        $address = new GiftShippingAddress(address1: 'הרצל 12', address2: 'בית פרטי', city: 'תל אביב', apartment: '3');

        $this->assertSame('דירה 3, בית פרטי', $address->orderLines()['address2']);
    }

    public function test_a_plans_floor_and_entrance_reach_the_order(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop, [
            InstallmentPlan::META_CHECKOUT_ADDRESS => [
                'street' => 'אירוסים', 'building_number' => '82', 'apartment_number' => '4',
                'floor' => '2', 'entrance' => 'ב', 'city' => 'מודיעין-מכבים-רעות', 'country' => 'IL',
            ],
        ]);

        // A guest (customer 0): the plan's own address is the one used.
        $blocks = WooOrderAddress::forOrder($plan, $shop, 0);

        $this->assertSame('אירוסים 82', $blocks['shipping']['address_1']);
        $this->assertSame('דירה 4, קומה 2, כניסה ב', $blocks['shipping']['address_2']);
    }

    public function test_a_renewal_for_a_store_account_ships_with_the_house_and_apartment(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/24*' => Http::response(['id' => 24] + $this->profile(), 200),
            '*' => Http::response([], 200),
        ]);

        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop);

        $blocks = WooOrderAddress::forOrder($plan, $shop, 24);

        $this->assertSame('אירוסים 82', $blocks['shipping']['address_1']);
        $this->assertSame('דירה 4, קומה 2, כניסה ב', $blocks['shipping']['address_2']);
        $this->assertSame('אירוסים 82', $blocks['billing']['address_1']);
    }

    public function test_the_customer_page_shows_the_house_and_apartment(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/24*' => Http::response(['id' => 24] + $this->profile(), 200),
            '*' => Http::response([], 200),
        ]);

        $shop = $this->wooShop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '24');

        $page = new CustomerDetail();
        $page->mount('24');

        $this->assertSame(
            'אירוסים 82, דירה 4, קומה 2, כניסה ב, מודיעין-מכבים-רעות, IL',
            $page->contact()->address?->displayLine(),
        );
    }

    /** @return array<string, mixed> a store customer profile with the LETS address fields */
    private function profile(): array
    {
        return [
            'first_name' => 'דנה',
            'billing' => [
                'first_name' => 'דנה',
                'address_1' => 'אירוסים',
                'address_2' => '',
                'city' => 'מודיעין-מכבים-רעות',
                'postcode' => '',
                'country' => 'IL',
            ],
            'shipping' => [
                'address_1' => '', 'city' => '', 'country' => '',
            ],
            'meta_data' => [
                ['key' => 'billing_building_number', 'value' => '82'],
                ['key' => 'billing_apartment_number', 'value' => '4'],
                ['key' => 'billing_floor', 'value' => '2'],
                ['key' => 'billing_entrance', 'value' => 'ב'],
            ],
        ];
    }

    private function wooShop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'address-parts.example.com',
            'name' => 'Address Parts',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://address-parts.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    /** @param  array<string, mixed>  $meta */
    private function plan(Shop $shop, array $meta = [], string $customerId = 'guest@example.com'): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_email' => 'guest@example.com',
            'customer_name' => 'דנה כהן',
            'external_customer_id' => $customerId,
        ] + ($meta !== [] ? ['meta' => $meta] : []));
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        return $plan;
    }
}
