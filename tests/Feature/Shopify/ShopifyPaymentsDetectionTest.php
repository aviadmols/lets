<?php

namespace Tests\Feature\Shopify;

use App\Filament\Pages\ManagePayPlusConnection;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\ShopifyPaymentsDetector;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Detecting Shopify Payments and what follows from it. The rules being proved:
 *
 *   1. a CONFIRMED Shopify-Payments store with no PayPlus credentials is tagged
 *      onto that rail, and its PayPlus-only settings disappear;
 *   2. a failed/denied lookup leaves the status UNKNOWN — never `inactive` —
 *      so nothing is hidden and no rail is changed on a guess;
 *   3. detection never overrules the merchant: a shop that already holds PayPlus
 *      credentials, or that was moved off the default rail, keeps its choice.
 */
final class ShopifyPaymentsDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_active_account_tags_the_shop_and_hides_payplus_settings(): void
    {
        $shop = $this->shop();
        $this->fakeAccount(['id' => 'gid://shopify/ShopifyPaymentsAccount/1', 'activated' => true]);

        $status = app(ShopifyPaymentsDetector::class)->detect($shop);

        $this->assertSame(Shop::SHOPIFY_PAYMENTS_ACTIVE, $status);

        $fresh = $shop->fresh();
        $this->assertTrue($fresh->hasShopifyPayments());
        $this->assertSame(Shop::RAIL_SHOPIFY_PAYMENTS, $fresh->subscriptionRail());
        $this->assertTrue($fresh->hidesPayplusSettings());
        $this->assertNotNull($fresh->shopify_payments_checked_at);

        // The PayPlus connection screen is gone from the sidebar AND denied by URL.
        Tenant::run($fresh, function (): void {
            $this->assertFalse(ManagePayPlusConnection::shouldRegisterNavigation());
            $this->assertFalse(ManagePayPlusConnection::canAccess());
        });
    }

    public function test_no_account_on_a_normal_store_is_inactive_and_changes_nothing(): void
    {
        $shop = $this->shop();
        $this->fakeAccount(null);

        $status = app(ShopifyPaymentsDetector::class)->detect($shop);

        $this->assertSame(Shop::SHOPIFY_PAYMENTS_INACTIVE, $status);
        $this->assertSame(Shop::RAIL_PAYPLUS, $shop->fresh()->subscriptionRail());
        $this->assertFalse($shop->fresh()->hidesPayplusSettings());
    }

    public function test_a_development_store_counts_as_test_so_the_rail_can_be_rehearsed(): void
    {
        $shop = $this->shop();
        // No payments account, but a partner DEVELOPMENT store — its gateway is
        // Shopify's test gateway, which is where the rail is meant to be tested.
        $this->fakeAccount(null, partnerDevelopment: true);

        $status = app(ShopifyPaymentsDetector::class)->detect($shop);

        $this->assertSame(Shop::SHOPIFY_PAYMENTS_TEST, $status);

        $fresh = $shop->fresh();
        $this->assertTrue($fresh->canUseShopifyPaymentsRail());
        // Testable, but never reported as live money.
        $this->assertFalse($fresh->hasShopifyPayments());
        $this->assertTrue($fresh->hasTestShopifyPayments());
        $this->assertSame(Shop::RAIL_SHOPIFY_PAYMENTS, $fresh->subscriptionRail());
    }

    public function test_an_unactivated_account_is_test_not_inactive(): void
    {
        $shop = $this->shop();
        $this->fakeAccount(['id' => 'gid://shopify/ShopifyPaymentsAccount/1', 'activated' => false]);

        $status = app(ShopifyPaymentsDetector::class)->detect($shop);

        $this->assertSame(Shop::SHOPIFY_PAYMENTS_TEST, $status);
        $this->assertTrue($shop->fresh()->canUseShopifyPaymentsRail());
    }

    public function test_a_denied_lookup_stays_unknown_and_hides_nothing(): void
    {
        $shop = $this->shop();

        $recorder = new RecordingShopifyClient();
        $recorder->graphqlThrows = new \RuntimeException('403 access denied');
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $status = app(ShopifyPaymentsDetector::class)->detect($shop);

        // Unknown — NOT "inactive". A failed lookup is not evidence of absence.
        $this->assertSame(Shop::SHOPIFY_PAYMENTS_UNKNOWN, $status);
        $this->assertSame(Shop::RAIL_PAYPLUS, $shop->fresh()->subscriptionRail());

        Tenant::run($shop->fresh(), function (): void {
            $this->assertTrue(ManagePayPlusConnection::canAccess());
        });
    }

    public function test_a_shop_with_payplus_credentials_is_never_retagged_or_hidden(): void
    {
        $shop = $this->shop();
        $shop->forceFill(['payplus_credentials' => [
            'api_key' => 'k', 'secret_key' => 's',
            'terminal_uid' => 't', 'payment_page_uid' => 'p',
        ]])->save();

        $this->fakeAccount(['id' => 'gid://shopify/ShopifyPaymentsAccount/1', 'activated' => true]);

        app(ShopifyPaymentsDetector::class)->detect($shop);

        $fresh = $shop->fresh();
        // Shopify Payments is confirmed, but the merchant's PayPlus setup stands.
        $this->assertTrue($fresh->hasShopifyPayments());
        $this->assertSame(Shop::RAIL_PAYPLUS, $fresh->subscriptionRail());
        $this->assertFalse($fresh->hidesPayplusSettings());
    }

    public function test_a_configured_payplus_shop_on_the_shopify_rail_keeps_its_screen(): void
    {
        $shop = $this->shop();
        $shop->forceFill([
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
            'payplus_credentials' => [
                'api_key' => 'k', 'secret_key' => 's',
                'terminal_uid' => 't', 'payment_page_uid' => 'p',
            ],
        ])->save();

        // Credentials exist → the screen stays reachable, or they'd be stranded.
        $this->assertFalse($shop->fresh()->hidesPayplusSettings());
    }

    // === Helpers ===

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'sp-detect.myshopify.com',
            'name' => 'SP Detect',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    /** @param array<string, mixed>|null $account */
    private function fakeAccount(?array $account, bool $partnerDevelopment = false): void
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [['data' => [
            'shopifyPaymentsAccount' => $account,
            'shop' => ['plan' => ['partnerDevelopment' => $partnerDevelopment]],
        ]]];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }
}
