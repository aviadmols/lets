<?php

namespace Tests\Feature\Customers;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Customers\CustomerContact;
use App\Domain\Customers\CustomerContactReader;
use App\Domain\Customers\CustomerContactWriter;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Reading and editing a customer's details.
 *
 * The details live in the STORE and nowhere else: read on open, written straight
 * back on save. That is deliberate — a copy here would drift from the merchant's
 * own customer record and would be a second pile of personal data to redact.
 *
 * The interesting cases are the ones where editing is NOT possible, because each
 * has a different answer the merchant can act on: a guest has no account, and
 * Shopify gates customer fields behind an approval.
 */
final class CustomerContactTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === Reading ===

    public function test_woocommerce_details_are_read_from_the_store(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/55' => Http::response([
                'id' => 55,
                'first_name' => 'דנה', 'last_name' => 'קונה', 'email' => 'dana@example.com',
                'billing' => ['phone' => '0501234567', 'address_1' => 'הרצל 1', 'city' => 'תל אביב'],
                'shipping' => ['address_1' => 'הרצל 1', 'city' => 'תל אביב', 'postcode' => '6100000', 'country' => 'IL'],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $contact = app(CustomerContactReader::class)->read($this->wooShop(), '55');

        $this->assertSame('דנה קונה', $contact->name());
        // WooCommerce keeps the phone on the BILLING block, not on the customer.
        $this->assertSame('0501234567', $contact->phone);
        $this->assertSame('הרצל 1', $contact->address?->address1);
        $this->assertTrue($contact->editable);
    }

    public function test_a_guest_has_no_account_to_edit(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        // A WooCommerce guest is stored as their EMAIL, not a customer id.
        $contact = app(CustomerContactReader::class)->read($this->wooShop(), 'guest@example.com');

        $this->assertFalse($contact->editable);
        $this->assertSame(CustomerContact::REASON_GUEST, $contact->reason);
    }

    public function test_a_shopify_refusal_names_the_approval_rather_than_erroring(): void
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlThrows = new \RuntimeException(
            'shopify.graphql_errors: This app is not approved to use the address1 field.'
        );
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $contact = app(CustomerContactReader::class)->read($this->shopifyShop(), '77');

        $this->assertFalse($contact->editable);
        $this->assertSame(CustomerContact::REASON_ACCESS_PENDING, $contact->reason);
    }

    public function test_an_unreachable_store_degrades_instead_of_throwing(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $contact = app(CustomerContactReader::class)->read($this->wooShop(), '55');

        // A screen that 500s because a shop hiccupped is worse than one that says so.
        $this->assertFalse($contact->editable);
        $this->assertSame(CustomerContact::REASON_UNAVAILABLE, $contact->reason);
    }

    // === Writing ===

    public function test_a_woocommerce_save_writes_billing_and_shipping(): void
    {
        Http::fake(['*/wp-json/wc/v3/customers/55' => Http::response(['id' => 55], 200)]);

        $result = app(CustomerContactWriter::class)->write($this->wooShop(), '55', $this->submitted());

        $this->assertTrue($result['ok']);

        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $body = $request->data();

            // A merchant editing "the address" means where it ships. WooCommerce
            // stores two blocks, so writing one leaves the next order on the old.
            return $body['shipping']['address_1'] === 'רוטשילד 5'
                && $body['billing']['address_1'] === 'רוטשילד 5'
                && $body['billing']['phone'] === '0509999999'
                && $body['first_name'] === 'דנה';
        });
    }

    public function test_a_rejected_save_reports_the_failure_and_changes_nothing(): void
    {
        Http::fake(['*/wp-json/wc/v3/customers/*' => Http::response(['message' => 'Invalid postcode'], 400)]);

        $result = app(CustomerContactWriter::class)->write($this->wooShop(), '55', $this->submitted());

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_a_guest_cannot_be_written_to(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $result = app(CustomerContactWriter::class)->write($this->wooShop(), 'guest@example.com', $this->submitted());

        $this->assertFalse($result['ok']);
        $this->assertSame(CustomerContact::REASON_GUEST, $result['error']);
        Http::assertNothingSent();
    }

    public function test_shopify_editing_stays_shut_until_the_scope_is_granted(): void
    {
        // The effective scopes come from SHOPIFY_OAUTH_SCOPES, which production
        // sets as an env var. Until write_customers is in it, the screen must
        // refuse rather than offer a save Shopify would reject.
        config(['shopify.oauth_scopes' => 'read_orders,read_customers']);

        $result = app(CustomerContactWriter::class)->write($this->shopifyShop(), '77', $this->submitted());

        $this->assertFalse($result['ok']);
        $this->assertSame(CustomerContact::REASON_ACCESS_PENDING, $result['error']);
    }

    public function test_shopify_declining_a_mutation_is_a_failed_save_not_a_silent_one(): void
    {
        config(['shopify.oauth_scopes' => 'read_customers,write_customers']);

        $recorder = new RecordingShopifyClient();
        // Shopify answers 200 with userErrors when it declines. Reading only the
        // HTTP status would report a save that never happened.
        $recorder->graphqlResponses = [[
            'data' => ['customerUpdate' => [
                'customer' => null,
                'userErrors' => [['field' => 'phone', 'message' => 'Phone is invalid']],
            ]],
        ]];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $result = app(CustomerContactWriter::class)->write($this->shopifyShop(), '77', $this->submitted());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Phone is invalid', (string) $result['error']);
    }

    // === Fixtures ===

    private function submitted(): CustomerContact
    {
        return new CustomerContact(
            firstName: 'דנה',
            lastName: 'קונה',
            phone: '0509999999',
            address: new GiftShippingAddress(
                firstName: 'דנה', lastName: 'קונה',
                address1: 'רוטשילד 5', city: 'תל אביב', zip: '6688218', countryCode: 'IL',
            ),
        );
    }

    private function wooShop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'contact.example.com',
            'name' => 'Contact',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://contact.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function shopifyShop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'contact.myshopify.com',
            'name' => 'Contact',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->forceFill(['shopify_access_token' => 'shpat_x'])->save();

        return $shop->fresh();
    }
}
