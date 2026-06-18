<?php

namespace Tests\Feature\Shopify;

use App\Http\Middleware\EmbeddedAuthenticate;
use App\Jobs\Products\ImportShopProductsJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Embedded-admin auth + MANAGED INSTALL (the keystone seam). On the first embedded
 * load App Bridge sends a session token; EmbeddedAuthenticate verifies it, exchanges
 * it for an offline token (managed install), creates the Shop + a shop-scoped admin
 * user, logs that user in, and binds the verified shop as tenant — all derived ONLY
 * from the verified JWT. A non-embedded request (no/invalid token) passes through so
 * the platform-admin login still works.
 *
 * Cases:
 *   (a) valid id_token for an UNINSTALLED shop → exchange faked → Shop row created +
 *       merchant user provisioned + request authenticated (no redirect to login).
 *   (b) already-installed live shop → loads + binds, NO second exchange.
 *   (c) NO token → passes through unauthenticated (platform-admin login intact).
 *   (d) a token for shop A never binds/logs in shop B (tenant isolation).
 */
final class EmbeddedAuthTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API_KEY = 'embedded_api_key';
    private const API_SECRET = 'embedded_api_secret';
    private const SHOP = 'gamma.myshopify.com';
    private const OTHER_SHOP = 'omega.myshopify.com';
    private const EXCHANGED_TOKEN = 'shpat_exchanged_offline_token';
    private const EXCHANGED_SCOPES = 'read_orders,read_products';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.api_key', self::API_KEY);
        config()->set('shopify.api_secret', self::API_SECRET);

        // A probe route guarded by the embedded-auth middleware (plus StartSession,
        // which Auth::login needs). It echoes whether the request is authenticated,
        // which user it logged in, and which shop it bound — captured DURING the
        // request (the middleware clears the tenant in finally afterwards).
        Route::middleware([StartSession::class, EmbeddedAuthenticate::class])
            ->get('/test/embedded-admin-probe', function () {
                return response()->json([
                    'authenticated' => auth()->check(),
                    'user_id' => auth()->id(),
                    'user_shop_id' => auth()->user()?->shop_id,
                    'bound_shop_id' => Tenant::id(),
                ]);
            });
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** (a) Managed install on first embedded load of an uninstalled shop. */
    public function test_first_load_of_uninstalled_shop_exchanges_token_and_installs(): void
    {
        Queue::fake();
        $this->fakeTokenExchange(self::SHOP);

        $this->assertDatabaseMissing('shops', ['shopify_domain' => self::SHOP]);

        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET);

        $response = $this->getJson('/test/embedded-admin-probe?id_token='.$jwt)
            ->assertOk()
            ->assertJsonPath('authenticated', true);

        // The Shop row was created and the offline token captured (encrypted).
        $shop = Shop::query()->where('shopify_domain', self::SHOP)->first();
        $this->assertNotNull($shop);
        $this->assertTrue($shop->isLive());
        $this->assertSame(self::EXCHANGED_TOKEN, $shop->shopifyAccessToken());

        // A shop-scoped merchant user was provisioned and is the one logged in
        // (captured DURING the request, before the finally cleared the tenant).
        $user = User::query()->where('shop_id', $shop->getKey())->first();
        $this->assertNotNull($user);
        $response->assertJsonPath('user_id', $user->getKey());
        $response->assertJsonPath('bound_shop_id', $shop->getKey());

        // Install side-effects were dispatched (tenant-bound jobs).
        Queue::assertPushed(RegisterShopifyWebhooksJob::class);
        Queue::assertPushed(ImportShopProductsJob::class);
    }

    /** (b) An already-installed live shop loads + binds without a second exchange. */
    public function test_installed_live_shop_binds_without_second_exchange(): void
    {
        Queue::fake();
        Http::fake(); // any token-exchange call would record here — we assert none.

        $shop = $this->makeInstalledShop(self::SHOP);
        $user = User::factory()->forShop($shop)->create();

        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET);

        $this->getJson('/test/embedded-admin-probe?id_token='.$jwt)
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user_id', $user->getKey())
            ->assertJsonPath('bound_shop_id', $shop->getKey());

        // No token exchange happened (the shop was already live).
        Http::assertNothingSent();
        // No re-install jobs dispatched on a normal load.
        Queue::assertNotPushed(RegisterShopifyWebhooksJob::class);
        Queue::assertNotPushed(ImportShopProductsJob::class);
    }

    /** (c) No token → pass through unauthenticated; the platform-admin login is intact. */
    public function test_request_without_token_passes_through_unauthenticated(): void
    {
        $this->getJson('/test/embedded-admin-probe')
            ->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('bound_shop_id', null);
    }

    /** (d) A token for shop A never binds or logs in shop B (tenant isolation). */
    public function test_token_for_one_shop_never_binds_another_shop(): void
    {
        Queue::fake();
        $this->fakeTokenExchange(self::SHOP);

        // A different, already-installed shop with its own user must stay untouched.
        $otherShop = $this->makeInstalledShop(self::OTHER_SHOP);
        $otherUser = User::factory()->forShop($otherShop)->create();

        // The JWT's dest is SHOP, not OTHER_SHOP.
        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET);

        $response = $this->getJson('/test/embedded-admin-probe?id_token='.$jwt)->assertOk();

        $boundShopId = $response->json('bound_shop_id');
        $loggedInUserId = $response->json('user_id');

        // The bound shop is SHOP's (freshly installed), never OTHER_SHOP's.
        $this->assertNotSame($otherShop->getKey(), $boundShopId);
        $this->assertNotSame($otherUser->getKey(), $loggedInUserId);

        $shop = Shop::query()->where('shopify_domain', self::SHOP)->first();
        $this->assertNotNull($shop);
        $this->assertSame($shop->getKey(), $boundShopId);
    }

    // === Helpers ===

    /** Fake the offline-token exchange POST to the given shop's token endpoint. */
    private function fakeTokenExchange(string $shop): void
    {
        Http::fake([
            'https://'.$shop.'/admin/oauth/access_token' => Http::response([
                'access_token' => self::EXCHANGED_TOKEN,
                'scope' => self::EXCHANGED_SCOPES,
            ], 200),
        ]);
    }

    private function makeInstalledShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->captureShopifyInstall('shpat_existing_token', 'read_orders');

        return $shop->fresh();
    }

    private function makeJwt(string $shop, string $aud, string $secret, ?int $exp = null): string
    {
        $now = time();
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode([
            'iss' => 'https://'.$shop.'/admin',
            'dest' => 'https://'.$shop.'/admin',
            'aud' => $aud,
            'sub' => '123',
            'exp' => $exp ?? ($now + 60),
            'nbf' => $now - 5,
            'iat' => $now,
        ]));
        $signature = $this->b64(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
