<?php

namespace Tests\Feature\Platform;

use App\Filament\Resources\ShopResource\Pages\ViewShop;
use App\Models\Shop;
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
}
