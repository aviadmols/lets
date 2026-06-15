<?php

namespace Tests\Feature\Products;

use App\Jobs\Products\ImportShopProductsJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\FakeProductShopifyClient;
use Tests\TestCase;

/**
 * W1 Phase C — product import (tenant-bound, idempotent, source-agnostic).
 *
 * Drives a canned 2-page Shopify products response through the real
 * ShopifyProductSource → ProductUpserter via ShopifyClientFactory::fake (no HTTP).
 * Proves: products + variants upsert, walking BOTH pages; a re-run is a no-op
 * (no duplicate rows); and only the BOUND shop's rows are written (no cross-tenant
 * write through the import path).
 */
final class ImportShopProductsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_import_upserts_products_and_variants_across_pages(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->fakeTwoPageCatalog();

        ImportShopProductsJob::dispatchSync($shop->id);

        Tenant::set($shop);
        $this->assertSame(3, Product::query()->count(), 'Both pages (2 + 1 products) imported.');
        $this->assertSame(3, ProductVariant::query()->count());

        $p1 = Product::query()->where('external_id', '5001')->firstOrFail();
        $this->assertSame('Alpha Tonic', $p1->title);
        $this->assertSame(Product::STATUS_ACTIVE, $p1->status);
        $this->assertSame(Product::ONLINE_PUBLISHED, $p1->online_store_status);
        $this->assertSame('shopify', $p1->source);
        $this->assertSame($shop->id, $p1->shop_id);
        $this->assertEqualsCanonicalizing(['subscription'], $p1->tags);
        $this->assertNotNull($p1->synced_at);

        $variant = $p1->variants()->first();
        $this->assertSame('500101', $variant->external_variant_id);
        $this->assertSame('SKU-1', $variant->sku);
        $this->assertSame('49.90', (string) $variant->price);

        // An ARCHIVED upstream product maps to the local "unlisted" status.
        $archived = Product::query()->where('external_id', '5003')->firstOrFail();
        $this->assertSame(Product::STATUS_UNLISTED, $archived->status);
    }

    public function test_re_running_the_import_is_idempotent_no_duplicates(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->fakeTwoPageCatalog();

        ImportShopProductsJob::dispatchSync($shop->id);
        ImportShopProductsJob::dispatchSync($shop->id); // reinstall / refresh

        Tenant::set($shop);
        $this->assertSame(3, Product::query()->count());
        $this->assertSame(3, ProductVariant::query()->count());
        $this->assertSame(1, Product::query()->where('external_id', '5001')->count());
    }

    public function test_import_writes_only_the_bound_shops_rows(): void
    {
        $shopA = $this->makeShop('alpha.myshopify.com');
        $shopB = $this->makeShop('beta.myshopify.com');
        $this->fakeTwoPageCatalog();

        // Import for A ONLY.
        ImportShopProductsJob::dispatchSync($shopA->id);

        Tenant::set($shopA);
        $this->assertSame(3, Product::query()->count());

        Tenant::set($shopB);
        $this->assertSame(0, Product::query()->count(), 'Shop B must have no products from A\'s import.');
    }

    public function test_uninstalled_shop_import_is_a_noop(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $shop->markUninstalled();
        $this->fakeTwoPageCatalog();

        ImportShopProductsJob::dispatchSync($shop->id);

        Tenant::set($shop);
        $this->assertSame(0, Product::query()->count(), 'A non-live shop is skipped.');
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    /** Page 1: products 5001 + 5002 (cursor "CUR1") → Page 2: 5003 (ARCHIVED). */
    private function fakeTwoPageCatalog(): void
    {
        $page1 = FakeProductShopifyClient::connection([
            FakeProductShopifyClient::productNode('gid://shopify/Product/5001', 'Alpha Tonic'),
            FakeProductShopifyClient::productNode('gid://shopify/Product/5002', 'Beta Blend'),
        ], endCursor: 'CUR1');

        $page2 = FakeProductShopifyClient::connection([
            FakeProductShopifyClient::productNode('gid://shopify/Product/5003', 'Gamma Gone', status: 'ARCHIVED', publishedAt: null),
        ], endCursor: null);

        $client = new FakeProductShopifyClient([
            '' => $page1,
            'CUR1' => $page2,
        ]);

        ShopifyClientFactory::fake(fn (Shop $shop) => $client);
    }
}
