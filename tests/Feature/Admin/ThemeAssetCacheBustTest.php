<?php

namespace Tests\Feature\Admin;

use App\Models\Shop;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Tenant;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Theme-asset delivery guards (two live admin-CSS bugs):
 *
 *  1. CACHE-BUST — the rc-admin.css bundle is registered by URL (not a Filament local
 *     asset), so without a ?v= query a browser keeps a STALE cached copy after the CSS
 *     changes (unstyled tabs/giant icons). themeAssetUrl() appends a filemtime buster.
 *
 *  2. HTTPS / MIXED CONTENT — panel() runs in the REGISTER phase, before TrustProxies
 *     and before AppServiceProvider::boot()'s forceScheme. Behind Railway's TLS proxy
 *     the register-phase scheme is http, so the asset()/favicon() URLs baked http:// and
 *     the browser BLOCKED them as mixed content on the https page. panel() now forces
 *     https first (in production).
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

        $this->assertStringContainsString('css/rc-admin.css?v=', $html);
    }

    public function test_production_panel_forces_https_so_assets_are_not_mixed_content(): void
    {
        // Simulate production: panel() must force https BEFORE it generates the theme +
        // favicon URLs, or they come out http:// and the browser blocks them on https.
        $this->app['env'] = 'production';

        (new AdminPanelProvider($this->app))->panel(Panel::make('test-https'));

        $this->assertStringStartsWith('https://', asset(AdminPanelProvider::THEME_ASSET_PATH));
        $this->assertStringStartsWith('https://', asset('favicon.ico'));
    }

    public function test_non_production_leaves_the_scheme_untouched(): void
    {
        // Local dev (http://localhost) must NOT be forced to https.
        $this->app['env'] = 'local';
        URL::forceScheme('http');

        (new AdminPanelProvider($this->app))->panel(Panel::make('test-http'));

        $this->assertStringStartsWith('http://', asset(AdminPanelProvider::THEME_ASSET_PATH));
    }
}
