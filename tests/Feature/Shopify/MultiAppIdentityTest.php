<?php

namespace Tests\Feature\Shopify;

use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Services\Shopify\ShopifyApps;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\ShopInstaller;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ONE deployment, TWO Partner apps (the public App-Store app + the custom
 * stage-1 app). These tests prove every identity seam resolves per app:
 *
 *   1. an OAuth install started with ?app=custom uses the CUSTOM client_id and
 *      the union scopes (and an unknown app param degrades to public);
 *   2. a webhook signed with the CUSTOM app's secret is accepted — with the
 *      public secret still configured and different;
 *   3. a session token minted by the CUSTOM app (aud = its api key, signed with
 *      its secret) authenticates the API surface end-to-end;
 *   4. the installer stamps WHICH app installed the shop, and a reinstall
 *      through the other app switches the stamp with the token.
 */
final class MultiAppIdentityTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PUBLIC_KEY = 'public_api_key';
    private const PUBLIC_SECRET = 'public_api_secret';
    private const CUSTOM_KEY = 'custom_api_key';
    private const CUSTOM_SECRET = 'custom_api_secret';
    private const SHOP = 'multi-app.myshopify.com';
    private const WEBHOOK_ENDPOINT = '/shopify/webhooks';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.api_key', self::PUBLIC_KEY);
        config()->set('shopify.api_secret', self::PUBLIC_SECRET);
        config()->set('shopify.webhook_secret', self::PUBLIC_SECRET);
        config()->set('shopify.apps.custom.api_key', self::CUSTOM_KEY);
        config()->set('shopify.apps.custom.api_secret', self::CUSTOM_SECRET);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_install_with_app_custom_uses_the_custom_client_id_and_union_scopes(): void
    {
        $response = $this->get('/shopify/install?shop='.self::SHOP.'&app=custom');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('client_id='.self::CUSTOM_KEY, $location);
        // The union scopes include the Shopify-Payments subscriptions rail.
        $this->assertStringContainsString('write_own_subscription_contracts', $location);
    }

    public function test_an_unknown_app_param_degrades_to_the_public_app(): void
    {
        $response = $this->get('/shopify/install?shop='.self::SHOP.'&app=nonsense');

        $response->assertRedirect();
        $this->assertStringContainsString(
            'client_id='.self::PUBLIC_KEY,
            (string) $response->headers->get('Location'),
        );
    }

    public function test_a_webhook_signed_with_the_custom_apps_secret_is_accepted(): void
    {
        $shop = $this->makeShop(); // installed through the custom app
        $raw = (string) json_encode(['id' => 42]);
        $hmac = base64_encode(hash_hmac('sha256', $raw, self::CUSTOM_SECRET, true));

        $response = $this->call('POST', self::WEBHOOK_ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop->shopify_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-custom-1',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        ], $raw);

        $response->assertStatus(202);
        $this->assertDatabaseHas('webhook_events', [
            'shop_id' => $shop->id,
            'webhook_id' => 'wh-custom-1',
            'hmac_valid' => true,
        ]);
    }

    public function test_a_wrongly_signed_webhook_is_still_rejected(): void
    {
        $this->makeShop();
        $raw = (string) json_encode(['id' => 42]);
        $hmac = base64_encode(hash_hmac('sha256', $raw, 'some-other-secret', true));

        $this->call('POST', self::WEBHOOK_ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-custom-2',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        ], $raw)->assertStatus(401);
    }

    public function test_a_custom_app_session_token_authenticates_the_api_surface(): void
    {
        $shop = $this->makeShop();
        $contract = $this->makeContract($shop, ownerCustomerId: 77);
        $this->fakePauseSuccess($contract);

        $response = $this->postJson('/subscriptions/api/pause', [
            'contract_gid' => (string) $contract->shopify_gid,
        ], ['Authorization' => 'Bearer '.$this->customToken(sub: '77')]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('PAUSED', $contract->fresh()->status);
    }

    public function test_the_installer_stamps_and_switches_the_partner_app_key(): void
    {
        Queue::fake();

        $shop = app(ShopInstaller::class)->installFromToken(self::SHOP, 'tok-custom', 'read_orders', 'custom');
        $this->assertSame(Shop::APP_CUSTOM, $shop->fresh()->shopifyAppKey());

        // Reinstall through the PUBLIC app — the stamp follows the new token.
        $shop = app(ShopInstaller::class)->installFromToken(self::SHOP, 'tok-public', 'read_orders', 'public');
        $this->assertSame(Shop::APP_PUBLIC, $shop->fresh()->shopifyAppKey());
        $this->assertSame(1, Shop::query()->where('shopify_domain', self::SHOP)->count());
    }

    public function test_the_registry_lists_both_apps_and_their_secrets(): void
    {
        $this->assertEqualsCanonicalizing(['public', 'custom'], ShopifyApps::keys());
        $this->assertEqualsCanonicalizing(
            [self::PUBLIC_SECRET, self::CUSTOM_SECRET],
            ShopifyApps::secrets(),
        );
    }

    // === Helpers ===

    private function makeShop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => self::SHOP,
            'name' => 'Multi App',
            'status' => Shop::STATUS_INSTALLED,
            'shopify_app_key' => Shop::APP_CUSTOM,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function makeContract(Shop $shop, int $ownerCustomerId): SubscriptionContract
    {
        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/7001',
            'shopify_customer_gid' => 'gid://shopify/Customer/'.$ownerCustomerId,
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'currency' => 'USD',
        ])->save();

        return $contract;
    }

    private function fakePauseSuccess(SubscriptionContract $contract): void
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [
            ['data' => ['subscriptionContractPause' => [
                'contract' => [
                    'id' => (string) $contract->shopify_gid,
                    'status' => 'PAUSED',
                    'nextBillingDate' => now()->addMonth()->toIso8601String(),
                    'currencyCode' => 'USD',
                    'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
                ],
                'userErrors' => [],
            ]]],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }

    /** A REAL HS256 session token minted by the CUSTOM app (aud = its api key). */
    private function customToken(string $sub): string
    {
        $now = time();
        $encode = static fn (array $part): string => rtrim(strtr(
            base64_encode((string) json_encode($part)),
            '+/',
            '-_',
        ), '=');

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => self::CUSTOM_KEY,
            'sub' => $sub,
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now,
            'jti' => uniqid(),
            'sid' => uniqid(),
        ]);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$payload, self::CUSTOM_SECRET, true),
        ), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
