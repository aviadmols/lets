<?php

namespace Tests\Feature\Shopify;

use App\Services\Shopify\ShopifyApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Detecting a token that predates a scope change.
 *
 * When the app starts asking for a new scope, Shopify grants it on the next app
 * open — but the offline token already stored here still carries the OLD grant,
 * and every call needing the new scope 403s ("Access denied for
 * sellingPlanGroupCreate…"). EmbeddedAuthenticate re-exchanges when this returns
 * anything, so the comparison must be right in BOTH directions:
 *
 *   - a genuinely missing scope must be reported, or the shop stays broken;
 *   - an IMPLIED scope must not be, or every healthy shop re-exchanges on every
 *     request. Shopify reports a granted `write_x` without the `read_x` it
 *     implies, which is what makes a naive set difference wrong.
 */
final class ScopeRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('shopify.apps.custom.api_key', 'custom_key');
        Config::set('shopify.apps.custom.api_secret', 'custom_secret');
        Config::set('shopify.apps.custom.oauth_scopes', 'read_products,write_products,write_purchase_options');
    }

    public function test_a_missing_scope_is_reported(): void
    {
        $missing = ShopifyApps::missingScopes('custom', 'write_products');

        $this->assertSame(['write_purchase_options'], $missing);
    }

    public function test_a_write_grant_covers_the_read_scope_it_implies(): void
    {
        // Shopify reports this grant WITHOUT read_products — it is implied.
        $missing = ShopifyApps::missingScopes('custom', 'write_products,write_purchase_options');

        $this->assertSame([], $missing, 'An implied read scope must not force a re-exchange.');
    }

    public function test_a_shop_with_no_recorded_scopes_needs_everything(): void
    {
        $this->assertSame(
            ['read_products', 'write_products', 'write_purchase_options'],
            ShopifyApps::missingScopes('custom', null),
        );
    }

    public function test_extra_granted_scopes_are_not_a_mismatch(): void
    {
        $missing = ShopifyApps::missingScopes('custom', 'write_products,write_purchase_options,read_orders');

        $this->assertSame([], $missing);
    }
}
