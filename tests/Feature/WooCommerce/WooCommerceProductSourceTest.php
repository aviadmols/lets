<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Product;
use App\Models\Shop;
use App\Services\Products\Sources\WooCommerceProductSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WooCommerceProductSource maps the WC REST shape into the source-agnostic DTOs (so the
 * import job + upserter + UI work unchanged). Real WooClientFactory + WooCommerceClient
 * over faked HTTP: a simple product yields one variant (its own sku/price); a variable
 * product fetches its variations; status/visibility/tags/image/timestamp map; page-number
 * pagination advances the cursor.
 */
final class WooCommerceProductSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_page_maps_simple_and_variable_products(): void
    {
        Http::fake([
            'https://store.example.com/wp-json/wc/v3/products/77/variations*' => Http::response([
                ['id' => 781, 'sku' => 'V-RED', 'price' => '60.00', 'attributes' => [['option' => 'Red']]],
                ['id' => 782, 'sku' => 'V-BLUE', 'price' => '65.00', 'attributes' => [['option' => 'Blue']]],
            ], 200),
            'https://store.example.com/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 10, 'name' => 'Simple Sofa', 'slug' => 'simple-sofa', 'status' => 'publish',
                    'catalog_visibility' => 'visible', 'type' => 'simple', 'sku' => 'SOFA-1', 'price' => '49.90',
                    'images' => [['src' => 'https://img/sofa.jpg']], 'tags' => [['name' => 'furniture']],
                    'date_modified_gmt' => '2026-01-02T10:00:00',
                ],
                [
                    'id' => 77, 'name' => 'Variable Chair', 'slug' => 'chair', 'status' => 'draft',
                    'catalog_visibility' => 'hidden', 'type' => 'variable', 'tags' => [],
                ],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $page = (new WooCommerceProductSource)->fetchPage($this->shop(), null);

        $this->assertCount(2, $page->items);
        $this->assertNull($page->nextCursor);

        $simple = $page->items[0];
        $this->assertSame('10', $simple->externalId);
        $this->assertSame('Simple Sofa', $simple->title);
        $this->assertSame(Product::STATUS_ACTIVE, $simple->status);
        $this->assertSame(Product::ONLINE_PUBLISHED, $simple->onlineStoreStatus);
        $this->assertSame('https://img/sofa.jpg', $simple->imageUrl);
        $this->assertSame(['furniture'], $simple->tags);
        $this->assertCount(1, $simple->variants);
        $this->assertSame('SOFA-1', $simple->variants[0]->sku);
        $this->assertSame('49.90', $simple->variants[0]->price);

        $variable = $page->items[1];
        $this->assertSame('77', $variable->externalId);
        $this->assertSame(Product::STATUS_DRAFT, $variable->status);
        $this->assertSame(Product::ONLINE_UNPUBLISHED, $variable->onlineStoreStatus);
        $this->assertCount(2, $variable->variants);
        $this->assertSame('781', $variable->variants[0]->externalId);
        $this->assertSame('Red', $variable->variants[0]->title);
        $this->assertSame('V-RED', $variable->variants[0]->sku);
    }

    public function test_pagination_advances_the_page_cursor(): void
    {
        Http::fake([
            'https://store.example.com/wp-json/wc/v3/products*' => Http::response([
                ['id' => 1, 'name' => 'A', 'type' => 'simple', 'status' => 'publish', 'price' => '10'],
            ], 200, ['X-WP-TotalPages' => '3']),
        ]);

        $page = (new WooCommerceProductSource)->fetchPage($this->shop(), null);

        $this->assertSame('2', $page->nextCursor); // page 1 of 3 → next page is 2
    }

    private function shop(): Shop
    {
        $shop = Shop::create([
            'name' => 'WC',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://store.example.com',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ];
        $shop->save();

        return $shop;
    }
}
