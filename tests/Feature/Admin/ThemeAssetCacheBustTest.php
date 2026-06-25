<?php

namespace Tests\Feature\Admin;

use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rc-admin.css theme bundle is registered by URL (not a Filament-managed local
 * asset), so it would otherwise carry NO version query — a browser keeps serving a
 * STALE cached copy after the tokens/components change, and the admin renders with the
 * old CSS (unstyled tabs, giant unsized icons) even though prod serves the right file.
 * AdminPanelProvider::themeAssetUrl() appends a filemtime cache-buster; this guards it.
 */
final class ThemeAssetCacheBustTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_link_the_theme_css_with_a_cache_buster(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'theme.myshopify.com',
            'name' => 'Theme',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $html = $this->get('/admin/post-purchase-offers')->assertOk()->getContent();

        // The bundle is linked AND carries a ?v= query so a content change busts caches.
        $this->assertStringContainsString('css/rc-admin.css?v=', $html);
    }
}
