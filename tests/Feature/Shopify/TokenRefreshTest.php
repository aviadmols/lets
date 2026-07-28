<?php

namespace Tests\Feature\Shopify;

use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\ShopifyToken;
use App\Services\Shopify\ShopifyTokenExchange;
use App\Services\Shopify\ShopifyTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Keeping the offline token usable.
 *
 * Shopify stopped accepting NON-EXPIRING offline tokens on the Admin API, and it
 * only mints an expiring one when the grant request ASKS (`expiring=1`) — there
 * is no dashboard setting. Omitting that parameter is what left the pilot store
 * holding a token that 403'd on every call while looking perfectly installed, so
 * the first test here pins the parameter itself.
 *
 * The rest pin the consequence: an expiring token must be renewed BEFORE it
 * lapses, from the background (a refresh token) as well as from the admin (a
 * session token) — the scheduler bills on days nobody opens the app.
 *
 * The opposite error matters too: a healthy token must NOT be re-minted on every
 * request, or each page load pays for a token exchange.
 */
final class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const DOMAIN = 'token-refresh.myshopify.com';
    private const TOKEN_URL = 'https://token-refresh.myshopify.com/admin/oauth/access_token';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shopify.apps.public.api_key' => 'key-public',
            'shopify.apps.public.api_secret' => 'secret-public',
        ]);
    }

    public function test_token_exchange_asks_shopify_for_an_expiring_token(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'shpat_fresh',
            'scope' => 'read_products',
            'expires_in' => 86400,
            'refresh_token' => 'shprt_fresh',
            'refresh_token_expires_in' => 7776000,
        ])]);

        $token = app(ShopifyTokenExchange::class)->exchange(self::DOMAIN, 'session-token');

        // The whole bug in one assertion: without `expiring=1` Shopify returns a
        // non-expiring token and the Admin API refuses every call made with it.
        Http::assertSent(fn ($request): bool => ($request->data()['expiring'] ?? null) === '1');

        $this->assertNotNull($token);
        $this->assertSame('shpat_fresh', $token->accessToken);
        $this->assertTrue($token->isExpiring());
        $this->assertSame('shprt_fresh', $token->refreshToken);
    }

    public function test_a_grant_stores_the_refresh_token_and_both_expiries(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken(
            accessToken: 'shpat_fresh',
            scope: 'read_products',
            expiresIn: 86400,
            refreshToken: 'shprt_fresh',
            refreshTokenExpiresIn: 7776000,
        ));

        $fresh = $shop->fresh();
        $this->assertSame('shprt_fresh', $fresh->shopifyRefreshToken());
        $this->assertNotNull($fresh->shopify_token_expires_at);
        $this->assertNotNull($fresh->shopify_refresh_token_expires_at);
        $this->assertFalse($fresh->shopifyTokenNeedsRefresh(), 'A healthy token must not be re-minted every request.');

        // The credential is at rest encrypted, never as the plain string.
        $this->assertNotSame('shprt_fresh', $fresh->getRawOriginal('shopify_refresh_token'));
    }

    public function test_a_stale_token_is_renewed_from_the_refresh_token_with_no_merchant_present(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'shpat_renewed',
            'scope' => 'read_products',
            'expires_in' => 86400,
            'refresh_token' => 'shprt_rotated',
            'refresh_token_expires_in' => 7776000,
        ])]);

        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_old', 'read_products', 60, 'shprt_old', 7776000));

        $this->assertTrue($shop->shopifyTokenNeedsRefresh());
        $this->assertTrue(app(ShopifyTokenRefresher::class)->ensureFresh($shop));

        Http::assertSent(fn ($request): bool => ($request->data()['grant_type'] ?? null) === 'refresh_token'
            && ($request->data()['refresh_token'] ?? null) === 'shprt_old');

        $fresh = $shop->fresh();
        $this->assertSame('shpat_renewed', $fresh->shopifyAccessToken());
        // The spent refresh token is replaced, or the NEXT renewal would fail.
        $this->assertSame('shprt_rotated', $fresh->shopifyRefreshToken());
    }

    public function test_a_legacy_token_with_no_expiry_is_stale_and_cannot_be_revived_in_the_background(): void
    {
        Http::fake(); // any call here would be a bug — assert none is made

        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_legacy', 'read_products'));

        $this->assertNull($shop->fresh()->shopify_token_expires_at);
        $this->assertTrue($shop->fresh()->shopifyTokenNeedsRefresh());

        // Nothing to spend: a legacy install predates refresh tokens, so only a
        // merchant opening the app (session token → token exchange) can fix it.
        $this->assertFalse(app(ShopifyTokenRefresher::class)->ensureFresh($shop->fresh()));
        Http::assertNothingSent();
    }

    public function test_an_expired_refresh_token_counts_as_no_refresh_token(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_old', 'read_products', 60, 'shprt_old', 7776000));
        $shop->forceFill(['shopify_refresh_token_expires_at' => now()->subDay()])->save();

        // Both states mean the same thing to every caller — this shop needs a
        // merchant — so they must read the same.
        $this->assertNull($shop->fresh()->shopifyRefreshToken());
    }

    public function test_a_healthy_token_is_left_alone(): void
    {
        Http::fake();

        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_new', 'read_products', 86400, 'shprt_new', 7776000));

        $this->assertTrue(app(ShopifyTokenRefresher::class)->ensureFresh($shop->fresh()));
        Http::assertNothingSent();
    }

    public function test_a_token_inside_the_refresh_window_is_refreshed_before_it_lapses(): void
    {
        $shop = $this->shop();
        // Still valid, but only just — a job starting now could outlive it.
        $shop->captureShopifyInstall(new ShopifyToken('shpat_expiring', 'read_products', Shop::TOKEN_REFRESH_WINDOW - 60));

        $this->assertTrue($shop->fresh()->shopifyTokenNeedsRefresh());
    }

    public function test_an_expired_token_is_refreshed(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_old', 'read_products', 3600));
        $shop->forceFill(['shopify_token_expires_at' => now()->subMinute()])->save();

        $this->assertTrue($shop->fresh()->shopifyTokenNeedsRefresh());
    }

    public function test_an_uninstalled_shop_has_nothing_to_refresh(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_x', 'read_products'));
        $shop->markUninstalled();

        // No token at all — refreshing would be meaningless, and claiming it is
        // needed would make the uninstalled shop look actionable. The refresh
        // token goes with it: a spent credential must not outlive the install.
        $fresh = $shop->fresh();
        $this->assertFalse($fresh->shopifyTokenNeedsRefresh());
        $this->assertNull($fresh->shopifyRefreshToken());
    }

    public function test_every_admin_client_is_built_on_a_renewed_token(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'shpat_renewed',
            'scope' => 'read_products',
            'expires_in' => 86400,
            'refresh_token' => 'shprt_rotated',
            'refresh_token_expires_in' => 7776000,
        ])]);

        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_old', 'read_products', 60, 'shprt_old', 7776000));

        // Background work never passes through the admin, so the factory is the
        // only place a lapsing token can be caught before it is used.
        ShopifyClientFactory::for($shop);

        $this->assertSame('shpat_renewed', $shop->fresh()->shopifyAccessToken());
    }

    public function test_a_refusal_to_renew_never_blanks_the_stored_scopes(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([], 401)]);

        $shop = $this->shop();
        $shop->captureShopifyInstall(new ShopifyToken('shpat_old', 'read_products', 60, 'shprt_old', 7776000));

        $this->assertFalse(app(ShopifyTokenRefresher::class)->ensureFresh($shop));

        // Losing the scope string would make every later request look like it is
        // missing every scope, and re-exchange forever.
        $this->assertSame('read_products', $shop->fresh()->shopify_scopes);
        $this->assertSame('shpat_old', $shop->fresh()->shopifyAccessToken());
    }

    private function shop(): Shop
    {
        return Shop::create([
            'shopify_domain' => self::DOMAIN,
            'name' => 'Token Refresh',
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }
}
