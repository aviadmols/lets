<?php

namespace Tests\Feature\Platform;

use App\Filament\Resources\ShopResource\Pages\ViewShop;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A WooCommerce shop has NO shopify_domain (nullable since W11 P0). The ShopResource
 * detail page (/admin/shops/{id}) returned a null page title → a TypeError 500 on the
 * typed getTitle(). Shop::displayDomain() is the platform-neutral, never-null label
 * the admin screens use.
 */
final class ViewShopWooCommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_domain_is_platform_neutral_and_never_null(): void
    {
        $this->assertSame('a.myshopify.com', (new Shop(['shopify_domain' => 'a.myshopify.com']))->displayDomain());
        $this->assertSame('store.example.com', (new Shop([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
        ]))->displayDomain());
        $this->assertSame('My Store', (new Shop(['name' => 'My Store']))->displayDomain());
    }

    public function test_view_shop_title_is_non_null_for_a_woocommerce_shop(): void
    {
        $wc = Shop::create([
            'name' => 'WC',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
        ]);
        $this->assertNull($wc->shopify_domain);

        $page = new ViewShop;
        $page->record = $wc;

        // The bug returned null here (typed getTitle 500s); now it's the WC domain.
        $this->assertSame('store.example.com', $page->getTitle());
    }

    public function test_view_shop_page_renders_end_to_end_for_a_woocommerce_shop(): void
    {
        // Reproduce the live /admin/shops/{id} 500 for a WC shop by rendering the WHOLE
        // page (blade + overview() aggregates + recentActivity) through HTTP, with
        // exceptions surfaced so the real cause shows instead of a generic 500.
        $this->withoutExceptionHandling();

        $admin = User::factory()->platformAdmin()->create();
        $wc = Shop::create([
            'name' => 'WC Store',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
        ]);

        $this->actingAs($admin)
            ->get(ViewShop::getUrl(['record' => $wc->getKey()]))
            ->assertOk()
            // The WooCommerce connect surface is present: the section, the live WP
            // connection status, and the plugin-download link (header action url).
            ->assertSee(__('platform.woo.section_title'))
            ->assertSee(__('platform.woo.connection_status'))
            ->assertSee('/admin/woocommerce/plugin/download');
    }

    public function test_woocommerce_connect_surface_is_hidden_for_a_shopify_shop(): void
    {
        $this->withoutExceptionHandling();

        $admin = User::factory()->platformAdmin()->create();
        $shopify = Shop::create([
            'shopify_domain' => 'sf.myshopify.com',
            'name' => 'SF',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        $this->actingAs($admin)
            ->get(ViewShop::getUrl(['record' => $shopify->getKey()]))
            ->assertOk()
            // A Shopify shop never shows the WooCommerce connect tooling.
            ->assertDontSee(__('platform.woo.section_title'))
            ->assertDontSee('/admin/woocommerce/plugin/download')
            ->assertSee(__('platform.overview.shopify'));
    }
}
