<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftAddressResolver;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Where a gift gets shipped.
 *
 * The SaaS stores no addresses, so this is read from the store at the moment the
 * order is created — the customer who moved last month gets their package at the
 * new address, and the app never becomes a stale second copy of the merchant's
 * customer records.
 *
 * It fails CLOSED: no address means no order, with a reason the merchant can act
 * on. A gift with nowhere to go is a package nobody can deliver.
 */
final class GiftAddressResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_customer_profile_is_preferred(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/*' => Http::response([
                'id' => 55,
                'shipping' => ['address_1' => 'New Street 9', 'city' => 'Haifa', 'country' => 'IL'],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        [$shop, $recipient] = $this->wooRecipient(customerId: '55', orderId: '900');

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        // Their CURRENT address, not the one on the order they subscribed with.
        $this->assertSame('New Street 9', $result['address']->address1);
        $this->assertSame(GiftRecipient::ADDRESS_FROM_PROFILE, $result['source']);
    }

    public function test_a_guest_falls_back_to_the_order_they_subscribed_with(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/orders/900' => Http::response([
                'id' => 900,
                'billing' => ['address_1' => 'Herzl 1', 'city' => 'Tel Aviv', 'country' => 'IL'],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        // Customer id 0 — a guest checkout has no profile to read.
        [$shop, $recipient] = $this->wooRecipient(customerId: '0', orderId: '900');

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        $this->assertSame('Herzl 1', $result['address']->address1);
        $this->assertSame(GiftRecipient::ADDRESS_FROM_ORDER, $result['source']);
    }

    public function test_a_profile_without_a_street_is_not_an_address(): void
    {
        Http::fake([
            // A country and a name, but nowhere to deliver to.
            '*/wp-json/wc/v3/customers/*' => Http::response([
                'id' => 55,
                'shipping' => ['first_name' => 'Dana', 'country' => 'IL'],
            ], 200),
            '*/wp-json/wc/v3/orders/*' => Http::response(['id' => 900], 200),
            '*' => Http::response([], 200),
        ]);

        [$shop, $recipient] = $this->wooRecipient(customerId: '55', orderId: '900');

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        $this->assertNull($result['address']);
        $this->assertSame(GiftRecipient::REASON_NO_ADDRESS, $result['reason']);
    }

    public function test_an_imported_member_ships_to_the_address_the_import_carried(): void
    {
        // The store knows nothing: no profile (no id, email finds nobody), no
        // origin order. Exactly the shape of the 1,362 imported members.
        Http::fake(['*' => Http::response([], 200)]);

        [$shop, $recipient] = $this->wooRecipient(customerId: '', orderId: '', meta: [
            'import' => ['address' => [
                'street' => 'ביאליק', 'building_number' => '7', 'apartment_number' => '3',
                'city' => 'רמת גן', 'zip_code' => '5252217', 'country' => 'IL',
            ]],
        ]);

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        // The same composed shape a renewal order ships to — one mapping.
        $this->assertSame('ביאליק 7', $result['address']->address1);
        $this->assertSame('דירה 3', $result['address']->address2);
        $this->assertSame('רמת גן', $result['address']->city);
        $this->assertSame(GiftRecipient::ADDRESS_FROM_PLAN, $result['source']);
    }

    public function test_an_imported_member_with_a_store_account_prefers_its_current_address(): void
    {
        Http::fake([
            // The email lookup finds the account they opened AFTER being imported…
            '*/wp-json/wc/v3/customers/88*' => Http::response([
                'id' => 88,
                'shipping' => ['address_1' => 'הרצל 12', 'city' => 'חיפה', 'country' => 'IL'],
            ], 200),
            '*/wp-json/wc/v3/customers*' => Http::response([['id' => 88]], 200),
            '*' => Http::response([], 200),
        ]);

        // The plan carries no store id AND an imported address — the account,
        // which the customer keeps current, still wins over the import snapshot.
        [$shop, $recipient] = $this->wooRecipient(customerId: '', orderId: '', meta: [
            'import' => ['address' => ['street' => 'ישן', 'building_number' => '1', 'city' => 'עיר ישנה']],
        ]);

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        $this->assertSame('הרצל 12', $result['address']->address1);
        $this->assertSame(GiftRecipient::ADDRESS_FROM_PROFILE, $result['source']);
    }

    public function test_a_half_filled_import_address_still_skips(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        // A city and nothing else is not a delivery instruction.
        [$shop, $recipient] = $this->wooRecipient(customerId: '', orderId: '', meta: [
            'import' => ['address' => ['city' => 'תל אביב']],
        ]);

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        $this->assertNull($result['address']);
        $this->assertSame(GiftRecipient::REASON_NO_ADDRESS, $result['reason']);
    }

    public function test_shopify_reads_the_customers_default_address(): void
    {
        $recorder = new RecordingShopifyClient;
        $recorder->graphqlResponses = [[
            'data' => ['customer' => ['defaultAddress' => [
                'address1' => 'Dizengoff 100', 'city' => 'Tel Aviv', 'countryCode' => 'IL',
            ]]],
        ]];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $recipient] = $this->shopifyRecipient(customerId: '77');

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        $this->assertSame('Dizengoff 100', $result['address']->address1);
        $this->assertSame(GiftRecipient::ADDRESS_FROM_PROFILE, $result['source']);
    }

    public function test_a_protected_data_refusal_names_the_approval_the_merchant_needs(): void
    {
        $recorder = new RecordingShopifyClient;
        // Shopify gates ADDRESS separately from name/email: a shop approved for one
        // can still be refused the other.
        $recorder->graphqlThrows = new \RuntimeException(
            'shopify.graphql_errors: This app is not approved to use the address1 field.'
        );
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $recipient] = $this->shopifyRecipient(customerId: '77');

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        // A pending approval the merchant can chase — not the same thing as a
        // customer who never gave an address.
        $this->assertNull($result['address']);
        $this->assertSame(GiftRecipient::REASON_ADDRESS_ACCESS_PENDING, $result['reason']);
    }

    public function test_a_transport_failure_never_throws(): void
    {
        $recorder = new RecordingShopifyClient;
        $recorder->graphqlThrows = new \RuntimeException('shopify.graphql_failed — status=500');
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $recipient] = $this->shopifyRecipient(customerId: '77');

        $result = Tenant::run($shop, fn () => app(GiftAddressResolver::class)->resolve($shop, $recipient));

        // One recipient's outage must not take down a run that has others to serve.
        $this->assertNull($result['address']);
        $this->assertSame(GiftRecipient::REASON_NO_ADDRESS, $result['reason']);
    }

    // === Fixtures ===

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: Shop, 1: GiftRecipient}
     */
    private function wooRecipient(string $customerId, string $orderId, array $meta = []): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'gift-addr.example.com',
            'name' => 'Gift Addr',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://gift-addr.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();
        $shop = $shop->fresh();

        return [$shop, $this->recipientFor($shop, array_filter([
            'external_customer_id' => $customerId,
            'external_order_id' => $orderId,
            'meta' => $meta !== [] ? $meta : null,
        ], static fn ($v): bool => $v !== null))];
    }

    /** @return array{0: Shop, 1: GiftRecipient} */
    private function shopifyRecipient(string $customerId): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'gift-addr.myshopify.com',
            'name' => 'Gift Addr',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->forceFill(['shopify_access_token' => 'shpat_x'])->save();
        $shop = $shop->fresh();

        return [$shop, $this->recipientFor($shop, ['shopify_customer_id' => $customerId])];
    }

    /** @param array<string, mixed> $planAttributes */
    private function recipientFor(Shop $shop, array $planAttributes): GiftRecipient
    {
        return Tenant::run($shop, function () use ($shop, $planAttributes): GiftRecipient {
            $plan = new InstallmentPlan;
            $plan->fill(array_merge([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_email' => 'dana@example.com',
            ], $planAttributes));
            $plan->forceFill([
                'shop_id' => $shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            $campaign = new GiftCampaign;
            $campaign->forceFill([
                'shop_id' => $shop->getKey(),
                'title' => 'Thanks',
                'min_cycles' => 1,
                'currency' => 'ILS',
                'status' => GiftCampaign::STATUS_GENERATING,
            ])->save();

            $recipient = new GiftRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'gift_campaign_id' => $campaign->getKey(),
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => GiftRecipient::STATUS_PENDING,
                'currency' => 'ILS',
            ])->save();

            return $recipient->fresh();
        });
    }
}
