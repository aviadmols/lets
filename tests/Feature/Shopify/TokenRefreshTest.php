<?php

namespace Tests\Feature\Shopify;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-minting the offline token.
 *
 * Shopify stopped accepting NON-EXPIRING offline tokens on the Admin API, which
 * means a shop still holding one is completely cut off — every call 403s — with
 * no outward sign. Those legacy tokens carry no expiry, so "no expiry recorded"
 * is exactly the signal that one must be replaced, and that is what migrates
 * existing installs without anyone reinstalling.
 *
 * The opposite error matters too: a healthy token must NOT be re-minted on every
 * request, or each page load pays for a token exchange.
 */
final class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_token_with_no_expiry_is_refreshed(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall('shpat_legacy', 'read_products', null);

        $this->assertNull($shop->fresh()->shopify_token_expires_at);
        $this->assertTrue($shop->fresh()->shopifyTokenNeedsRefresh());
    }

    public function test_a_fresh_expiring_token_is_left_alone(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall('shpat_new', 'read_products', 86400);

        $fresh = $shop->fresh();
        $this->assertNotNull($fresh->shopify_token_expires_at);
        $this->assertFalse($fresh->shopifyTokenNeedsRefresh(), 'A healthy token must not be re-minted every request.');
    }

    public function test_a_token_inside_the_refresh_window_is_refreshed_before_it_lapses(): void
    {
        $shop = $this->shop();
        // Still valid, but only just — a job starting now could outlive it.
        $shop->captureShopifyInstall('shpat_expiring', 'read_products', Shop::TOKEN_REFRESH_WINDOW - 60);

        $this->assertTrue($shop->fresh()->shopifyTokenNeedsRefresh());
    }

    public function test_an_expired_token_is_refreshed(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall('shpat_old', 'read_products', 3600);
        $shop->forceFill(['shopify_token_expires_at' => now()->subMinute()])->save();

        $this->assertTrue($shop->fresh()->shopifyTokenNeedsRefresh());
    }

    public function test_an_uninstalled_shop_has_nothing_to_refresh(): void
    {
        $shop = $this->shop();
        $shop->captureShopifyInstall('shpat_x', 'read_products', null);
        $shop->markUninstalled();

        // No token at all — refreshing would be meaningless, and claiming it is
        // needed would make the uninstalled shop look actionable.
        $this->assertFalse($shop->fresh()->shopifyTokenNeedsRefresh());
    }

    private function shop(): Shop
    {
        return Shop::create([
            'shopify_domain' => 'token-refresh.myshopify.com',
            'name' => 'Token Refresh',
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }
}
