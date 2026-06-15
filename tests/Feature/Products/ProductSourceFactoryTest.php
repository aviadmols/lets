<?php

namespace Tests\Feature\Products;

use App\Models\Shop;
use App\Services\Products\ProductSourceFactory;
use App\Services\Products\Sources\ShopifyProductSource;
use App\Services\Products\Sources\WooCommerceProductSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W1 Phase A — the source abstraction stands. The factory keys off the shop's
 * `platform` (defaulting to Shopify), resolves the Stage-2 Woo placeholder for a
 * woocommerce shop, and honours the test fake() hook.
 */
final class ProductSourceFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ProductSourceFactory::clearFake();
        parent::tearDown();
    }

    public function test_default_platform_resolves_the_shopify_source(): void
    {
        $shop = Shop::create(['shopify_domain' => 'a.myshopify.com', 'name' => 'a', 'status' => Shop::STATUS_INSTALLED]);

        // No platform set ⇒ the accessor defaults to shopify.
        $this->assertSame(Shop::PLATFORM_SHOPIFY, $shop->platform);
        $this->assertInstanceOf(ShopifyProductSource::class, ProductSourceFactory::for($shop));
    }

    public function test_woocommerce_platform_resolves_the_stage2_placeholder(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'b.myshopify.com',
            'name' => 'b',
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'status' => Shop::STATUS_INSTALLED,
        ]);

        $source = ProductSourceFactory::for($shop);
        $this->assertInstanceOf(WooCommerceProductSource::class, $source);
        $this->assertSame(Shop::PLATFORM_WOOCOMMERCE, $source->platform());
    }

    public function test_unknown_platform_value_falls_back_to_shopify(): void
    {
        $shop = Shop::create(['shopify_domain' => 'c.myshopify.com', 'name' => 'c', 'status' => Shop::STATUS_INSTALLED]);
        $shop->forceFill(['platform' => 'magento'])->save();

        $this->assertSame(Shop::PLATFORM_SHOPIFY, $shop->fresh()->platform, 'Unknown value normalises to shopify.');
        $this->assertInstanceOf(ShopifyProductSource::class, ProductSourceFactory::for($shop->fresh()));
    }

    public function test_fake_hook_overrides_resolution(): void
    {
        $shop = Shop::create(['shopify_domain' => 'd.myshopify.com', 'name' => 'd', 'status' => Shop::STATUS_INSTALLED]);
        $stub = new WooCommerceProductSource();
        ProductSourceFactory::fake($stub);

        $this->assertSame($stub, ProductSourceFactory::for($shop));
    }
}
