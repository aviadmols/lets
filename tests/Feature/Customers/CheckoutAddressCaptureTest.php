<?php

namespace Tests\Feature\Customers;

use App\Domain\Customers\CheckoutAddressCapture;
use App\Domain\Customers\CustomerContact;
use App\Filament\Pages\CustomerDetail;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\WooCommerce\WooOrderAddress;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A checkout subscriber keeps the address they typed at checkout.
 *
 * A WooCommerce shopper can subscribe as a guest. Their address then lived on the
 * order alone: no store account to read, nothing on the plan. The subscription
 * screen and the customer page showed no address, and a renewal order shipped
 * with nowhere to go.
 */
final class CheckoutAddressCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_checkout_address_is_kept_on_the_plan_in_its_own_parts(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop);

        // The order exactly as the plugin composes it: street + building on line 1,
        // the labelled parts on line 2.
        $written = app(CheckoutAddressCapture::class)->fromWooOrder($plan, $this->order());

        $this->assertTrue($written);
        $this->assertSame([
            'street' => 'אליהו הנביא',
            'building_number' => '18',
            'apartment_number' => '4',
            'floor' => '2',
            'entrance' => 'ב',
            'city' => 'חיפה',
            'zip_code' => '3304118',
            'country' => 'IL',
        ], $plan->fresh()->meta[InstallmentPlan::META_CHECKOUT_ADDRESS]);

        $this->assertSame([
            'street' => 'אליהו הנביא',
            'building_number' => '18',
            'apartment_number' => '4',
            'floor' => '2',
            'entrance' => 'ב',
            'city' => 'חיפה',
            'zip_code' => '3304118',
            'country' => 'IL',
        ], $plan->fresh()->contactAddress());
    }

    public function test_billing_is_used_when_the_order_has_no_shipping_block(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop);

        app(CheckoutAddressCapture::class)->fromWooOrder($plan, [
            'id' => 900,
            'shipping' => [],
            'billing' => ['address_1' => 'הרצל 12', 'city' => 'תל אביב', 'country' => 'IL'],
        ]);

        $this->assertSame(
            ['street' => 'הרצל', 'building_number' => '12', 'city' => 'תל אביב', 'country' => 'IL'],
            $plan->fresh()->contactAddress(),
        );
    }

    public function test_an_address_the_plan_already_holds_is_never_overwritten(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop, meta: [
            InstallmentPlan::META_CONTACT_ADDRESS => ['street' => 'מתוקן', 'building_number' => '1', 'city' => 'ירושלים'],
        ]);

        $written = app(CheckoutAddressCapture::class)->fromWooOrder($plan, $this->order());

        $this->assertFalse($written);
        $this->assertArrayNotHasKey(InstallmentPlan::META_CHECKOUT_ADDRESS, $plan->fresh()->meta);
        $this->assertSame('ירושלים', $plan->fresh()->contactAddress()['city']);
    }

    public function test_an_order_without_a_street_writes_nothing(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop);

        $written = app(CheckoutAddressCapture::class)->fromWooOrder($plan, [
            'id' => 900,
            'billing' => ['first_name' => 'דנה', 'country' => 'IL'],
        ]);

        $this->assertFalse($written);
        $this->assertSame([], $plan->fresh()->contactAddress());
    }

    public function test_capture_keeps_the_meta_other_writers_put_on_the_plan(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop, meta: [InstallmentPlan::META_ITEM_TITLE => 'קופסה חודשית']);

        app(CheckoutAddressCapture::class)->fromWooOrder($plan, $this->order());

        $meta = $plan->fresh()->meta;
        $this->assertSame('קופסה חודשית', $meta[InstallmentPlan::META_ITEM_TITLE]);
        $this->assertArrayHasKey(InstallmentPlan::META_CHECKOUT_ADDRESS, $meta);
    }

    public function test_a_guest_renewal_order_ships_to_the_checkout_address(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop);
        app(CheckoutAddressCapture::class)->fromWooOrder($plan, $this->order());

        // Customer id 0 — a guest: there is no store profile, so before this the
        // order went out with no address at all.
        $blocks = WooOrderAddress::forOrder($plan->fresh(), $shop, 0);

        $this->assertSame('אליהו הנביא 18', $blocks['shipping']['address_1']);
        $this->assertSame('חיפה', $blocks['shipping']['city']);
    }

    public function test_the_customer_page_shows_a_guests_checkout_address(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop, customerId: 'guest@example.com');
        app(CheckoutAddressCapture::class)->fromWooOrder($plan, $this->order());

        $page = new CustomerDetail();
        $page->mount('guest@example.com');
        $contact = $page->contact();

        // It used to stop at "guest — no account".
        $this->assertNull($contact->reason);
        $this->assertSame('אליהו הנביא 18', $contact->address?->address1);
        $this->assertSame('חיפה', $contact->address?->city);
        $this->assertFalse($contact->editable, 'a guest has no store record to write back to');
    }

    public function test_a_store_account_with_an_address_still_wins_on_the_customer_page(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/55' => Http::response([
                'id' => 55,
                'first_name' => 'דנה',
                'shipping' => ['address_1' => 'חדש 9', 'city' => 'נתניה', 'country' => 'IL'],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $shop = $this->wooShop();
        Tenant::set($shop);
        $plan = $this->plan($shop, customerId: '55');
        app(CheckoutAddressCapture::class)->fromWooOrder($plan, $this->order());

        $page = new CustomerDetail();
        $page->mount('55');

        $this->assertSame('נתניה', $page->contact()->address?->city);
        $this->assertNotSame(CustomerContact::REASON_GUEST, $page->contact()->reason);
    }

    public function test_the_backfill_fills_checkout_plans_from_their_origin_order(): void
    {
        $shop = $this->wooShop();

        Tenant::set($shop);
        $guest = $this->plan($shop, orderId: '900');
        $alreadyHasOne = $this->plan($shop, orderId: '901', meta: [
            'import' => ['address' => ['street' => 'ביאליק', 'building_number' => '7', 'city' => 'רמת גן']],
        ]);
        $imported = $this->plan($shop, orderId: null);
        Tenant::clear();

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([$this->order()], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('lets:subscriptions:backfill-checkout-addresses', ['--shop' => $shop->getKey()])
            ->assertSuccessful();

        // ONE bulk request, for the one plan that needed it.
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/orders')
            && str_contains(urldecode($r->url()), 'include=900'));

        Tenant::set($shop);
        $this->assertSame('חיפה', $guest->fresh()->contactAddress()['city']);
        $this->assertSame('רמת גן', $alreadyHasOne->fresh()->contactAddress()['city']);
        $this->assertArrayNotHasKey(InstallmentPlan::META_CHECKOUT_ADDRESS, (array) $alreadyHasOne->fresh()->meta);
        $this->assertSame([], $imported->fresh()->contactAddress());
    }

    public function test_a_backfill_dry_run_writes_nothing(): void
    {
        $shop = $this->wooShop();

        Tenant::set($shop);
        $guest = $this->plan($shop, orderId: '900');
        Tenant::clear();

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([$this->order()], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('lets:subscriptions:backfill-checkout-addresses', [
            '--shop' => $shop->getKey(),
            '--dry-run' => true,
        ])->assertSuccessful();

        Tenant::set($shop);
        $this->assertSame([], $guest->fresh()->contactAddress());
    }

    /** @return array<string, mixed> a paid WC order, as the REST API returns it */
    private function order(): array
    {
        return [
            'id' => 900,
            'billing' => [
                'first_name' => 'דנה', 'last_name' => 'כהן',
                'address_1' => 'אליהו הנביא 18', 'address_2' => 'דירה 4 · קומה 2 · כניסה ב',
                'city' => 'חיפה', 'postcode' => '3304118', 'country' => 'IL',
                'email' => 'guest@example.com', 'phone' => '0501234567',
            ],
            'shipping' => [
                'first_name' => 'דנה', 'last_name' => 'כהן',
                'address_1' => 'אליהו הנביא 18', 'address_2' => 'דירה 4 · קומה 2 · כניסה ב',
                'city' => 'חיפה', 'postcode' => '3304118', 'country' => 'IL',
            ],
        ];
    }

    private function wooShop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'checkout-addr.example.com',
            'name' => 'Checkout Addr',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://checkout-addr.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    /** @param  array<string, mixed>  $meta */
    private function plan(Shop $shop, string $customerId = 'guest@example.com', ?string $orderId = '900', array $meta = []): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->fill(array_filter([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_email' => 'guest@example.com',
            'customer_name' => 'דנה כהן',
            'external_customer_id' => $customerId,
            'external_order_id' => $orderId,
            'meta' => $meta !== [] ? $meta : null,
        ], static fn ($v): bool => $v !== null));
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        return $plan;
    }
}
