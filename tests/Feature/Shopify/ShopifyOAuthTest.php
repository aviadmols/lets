<?php

namespace Tests\Feature\Shopify;

use App\Jobs\Products\ImportShopProductsJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The public-app OAuth contract (shopify-integration.md §3): the callback
 * verifies HMAC + state, exchanges code → OFFLINE token, upserts the Shop with an
 * ENCRYPTED token, and dispatches the per-shop webhook registration job. Reinstall
 * reuses the same Shop row (matched by domain).
 */
final class ShopifyOAuthTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API_KEY = 'test_api_key';
    private const API_SECRET = 'test_api_secret';
    private const SHOP = 'gamma.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.api_key', self::API_KEY);
        config()->set('shopify.api_secret', self::API_SECRET);
        config()->set('shopify.app_handle', 'payplus-subscriptions');
        Queue::fake();
    }

    public function test_install_redirects_to_shopify_authorize_with_state(): void
    {
        $response = $this->get('/shopify/install?shop='.self::SHOP);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('https://'.self::SHOP.'/admin/oauth/authorize', $location);
        $this->assertStringContainsString('client_id='.self::API_KEY, $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertNotNull(Cache::get('shopify:oauth_state:'.self::SHOP));
    }

    public function test_install_rejects_invalid_shop_param_422(): void
    {
        $this->get('/shopify/install?shop=evil.example.com')->assertStatus(422);
    }

    public function test_callback_stores_encrypted_token_and_upserts_shop(): void
    {
        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_offline_secret',
                'scope' => 'read_orders,write_orders',
            ], 200),
        ]);

        $query = $this->signedCallbackQuery(state: $this->seedState());

        $response = $this->get('/shopify/callback?'.http_build_query($query));

        // Redirects into the embedded admin app home.
        $response->assertRedirect('https://'.self::SHOP.'/admin/apps/payplus-subscriptions');

        $shop = Shop::query()->where('shopify_domain', self::SHOP)->firstOrFail();
        $this->assertSame(Shop::STATUS_INSTALLED, $shop->status);
        $this->assertSame('read_orders,write_orders', $shop->shopify_scopes);
        $this->assertSame('shpat_offline_secret', $shop->shopifyAccessToken());

        // The persisted column is CIPHERTEXT, not the plaintext token.
        $rawColumn = (string) DB::table('shops')->where('id', $shop->id)->value('shopify_access_token');
        $this->assertNotSame('shpat_offline_secret', $rawColumn);
        $this->assertSame('shpat_offline_secret', Crypt::decryptString($rawColumn));

        // Webhook registration is dispatched for this shop.
        Queue::assertPushed(RegisterShopifyWebhooksJob::class, fn ($job) => $job->shopId === $shop->id);

        // Catalog backfill is dispatched for this shop, and platform is set.
        Queue::assertPushed(ImportShopProductsJob::class, fn ($job) => $job->shopId === $shop->id);
        $this->assertSame(Shop::PLATFORM_SHOPIFY, $shop->platform);
    }

    public function test_callback_rejects_bad_hmac_401(): void
    {
        $query = $this->signedCallbackQuery(state: $this->seedState());
        $query['hmac'] = 'tampered';

        $this->get('/shopify/callback?'.http_build_query($query))->assertStatus(401);
    }

    public function test_callback_rejects_unknown_state_401(): void
    {
        // No state seeded in cache ⇒ pull returns null ⇒ 401.
        $query = $this->signedCallbackQuery(state: 'never-issued');

        $this->get('/shopify/callback?'.http_build_query($query))->assertStatus(401);
    }

    public function test_reinstall_reuses_same_shop_row(): void
    {
        $existing = Shop::create([
            'shopify_domain' => self::SHOP,
            'name' => self::SHOP,
            'status' => Shop::STATUS_UNINSTALLED,
        ]);

        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new_after_reinstall',
                'scope' => 'read_orders',
            ], 200),
        ]);

        $query = $this->signedCallbackQuery(state: $this->seedState());
        $this->get('/shopify/callback?'.http_build_query($query))->assertRedirect();

        $this->assertSame(1, Shop::query()->where('shopify_domain', self::SHOP)->count());
        $fresh = $existing->fresh();
        $this->assertSame(Shop::STATUS_INSTALLED, $fresh->status);
        $this->assertSame('shpat_new_after_reinstall', $fresh->shopifyAccessToken());
        $this->assertNull($fresh->uninstalled_at);
    }

    // === Helpers ===

    private function seedState(): string
    {
        $nonce = 'state-nonce-123';
        Cache::put('shopify:oauth_state:'.self::SHOP, $nonce, 300);

        return $nonce;
    }

    /** @return array<string, string> a callback query whose hmac is valid. */
    private function signedCallbackQuery(string $state): array
    {
        $query = [
            'code' => 'auth_code_123',
            'shop' => self::SHOP,
            'state' => $state,
            'timestamp' => (string) time(),
        ];

        ksort($query);
        $message = collect($query)->map(fn ($v, $k) => $k.'='.$v)->implode('&');
        $query['hmac'] = hash_hmac('sha256', $message, self::API_SECRET);

        return $query;
    }
}
