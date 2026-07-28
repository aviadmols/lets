<?php

namespace Tests\Feature\Shopify;

use App\Http\Middleware\SessionTokenAuth;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use App\Services\Shopify\ShopifyToken;

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

    public function test_a_checkout_extension_token_with_a_bare_host_is_accepted(): void
    {
        $shop = $this->makeInstalledShop();

        // A checkout / customer-account UI extension sends iss and dest as the BARE
        // host, not as https://{shop}/admin. parse_url() reads a scheme-less string
        // as a PATH, so the host came back empty and every extension token was
        // rejected as unverifiable — which is what left the thank-you upsell blank
        // while the admin worked perfectly.
        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET, bareHost: true);

        $this->withToken($jwt)->getJson('/test/embedded-probe')
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shop->id);
    }

    public function test_a_token_whose_iss_and_dest_name_different_shops_is_rejected(): void
    {
        $this->makeInstalledShop();

        // Accepting the bare-host shape must not weaken the check that both claims
        // name the SAME shop — otherwise a token minted for one store could be
        // replayed against another.
        $jwt = $this->makeJwt(self::SHOP, self::API_KEY, self::API_SECRET, issShop: 'attacker.myshopify.com');

        $this->withToken($jwt)->getJson('/test/embedded-probe')->assertStatus(401);
    }

    public function test_a_dest_that_is_not_a_shop_domain_is_rejected(): void
    {
        $this->makeInstalledShop();

        // The host still has to BE a shop domain; normalising the shape must not
        // start letting arbitrary hosts through.
        $jwt = $this->makeJwt('evil.example.com', self::API_KEY, self::API_SECRET, bareHost: true);

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
        $shop->captureShopifyInstall(new ShopifyToken('shpat_token', 'read_orders', 86400));

        return $shop->fresh();
    }

    /**
     * @param  bool  $bareHost  emit iss/dest as the bare host (a checkout / customer-
     *                          account UI extension) instead of https://{shop}/admin
     *                          (the embedded admin's App Bridge)
     * @param  string|null  $issShop  override iss only, to prove both claims must
     *                                still name the same shop
     */
    private function makeJwt(
        string $shop,
        string $aud,
        string $secret,
        ?int $exp = null,
        bool $bareHost = false,
        ?string $issShop = null,
    ): string {
        $now = time();
        $format = static fn (string $s): string => $bareHost ? $s : 'https://'.$s.'/admin';
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode([
            'iss' => $format($issShop ?? $shop),
            'dest' => $format($shop),
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
