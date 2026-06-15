<?php

namespace Tests\Feature\Shopify;

use App\Http\Middleware\SessionTokenAuth;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Embedded-admin session-token auth (§6): a valid App Bridge JWT (HS256 w/ the app
 * secret) authenticates the request and binds the matching Shop as Tenant; an
 * invalid/expired/foreign token is rejected 401 (fail closed) with the App-Bridge
 * re-auth header.
 */
final class SessionTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API_KEY = 'embedded_api_key';
    private const API_SECRET = 'embedded_api_secret';
    private const SHOP = 'delta.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.api_key', self::API_KEY);
        config()->set('shopify.api_secret', self::API_SECRET);

        // A probe route guarded by the session-token middleware that echoes the
        // tenant the middleware bound.
        Route::middleware(SessionTokenAuth::class)->get('/test/embedded-probe', function () {
            return response()->json(['bound_shop_id' => Tenant::id()]);
        });
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_valid_session_token_binds_the_correct_shop(): void
    {
        $shop = $this->makeInstalledShop();
        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET);

        $this->withToken($jwt)->getJson('/test/embedded-probe')
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shop->id);

        // Cleared after the request (no leak).
        $this->assertNull(Tenant::current());
    }

    public function test_token_signed_with_wrong_secret_is_rejected(): void
    {
        $this->makeInstalledShop();
        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, 'WRONG_SECRET');

        $this->withToken($jwt)->getJson('/test/embedded-probe')
            ->assertStatus(401)
            ->assertHeader('X-Shopify-API-Request-Failure-Reauthorize', '1');
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->makeInstalledShop();
        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET, exp: time() - 60);

        $this->withToken($jwt)->getJson('/test/embedded-probe')->assertStatus(401);
    }

    public function test_token_for_unknown_or_uninstalled_shop_is_rejected(): void
    {
        // No Shop row for the dest shop ⇒ reject.
        $jwt = $this->makeJwt('ghost.myshopify.com', self::API_KEY, self::API_SECRET);

        $this->withToken($jwt)->getJson('/test/embedded-probe')->assertStatus(401);
    }

    // === Helpers ===

    private function makeInstalledShop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => self::SHOP,
            'name' => self::SHOP,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->captureShopifyInstall('shpat_token', 'read_orders');

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
