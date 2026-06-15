<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RELEASE-BLOCKER gate for the W1 product tables: creating a Product auto-stamps
 * shop_id from the bound tenant, and Shop A can NEVER read Shop B's products,
 * variants, or plan templates through the BelongsToShop global scope.
 */
final class ProductTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_creating_a_product_auto_stamps_shop_id(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        Tenant::set($shopA);

        // Create WITHOUT passing shop_id — BelongsToShop must stamp it.
        $product = Product::create([
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => '5001',
            'title' => 'Test product',
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => Product::ONLINE_PUBLISHED,
        ]);

        $this->assertSame($shopA->id, $product->shop_id);
    }

    public function test_shop_a_cannot_read_shop_b_products_variants_or_plans(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        [$productA, $variantA, $planA] = $this->makeCatalogFor($shopA, '5001');
        [$productB, $variantB, $planB] = $this->makeCatalogFor($shopB, '6001');

        // Bound to A: only A's rows are visible, B's are invisible by id.
        Tenant::set($shopA);
        $this->assertCount(1, Product::all());
        $this->assertCount(1, ProductVariant::all());
        $this->assertCount(1, ProductSubscriptionPlan::all());
        $this->assertSame($productA->id, Product::first()->id);
        $this->assertNull(Product::find($productB->id), 'Shop A must not resolve Shop B product by id.');
        $this->assertNull(ProductVariant::find($variantB->id));
        $this->assertNull(ProductSubscriptionPlan::find($planB->id));

        // Bound to B: mirror image.
        Tenant::set($shopB);
        $this->assertCount(1, Product::all());
        $this->assertSame($productB->id, Product::first()->id);
        $this->assertNull(Product::find($productA->id));
        $this->assertNull(ProductVariant::find($variantA->id));
        $this->assertNull(ProductSubscriptionPlan::find($planA->id));
    }

    public function test_unbound_tenant_fails_closed(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $this->makeCatalogFor($shopA, '5001');

        Tenant::clear();

        // No tenant bound → the scope matches shop_id = null → zero rows. Never leak.
        $this->assertCount(0, Product::all());
        $this->assertCount(0, ProductVariant::all());
        $this->assertCount(0, ProductSubscriptionPlan::all());
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductSubscriptionPlan} */
    private function makeCatalogFor(Shop $shop, string $externalId): array
    {
        return Tenant::run($shop, function () use ($externalId): array {
            $product = Product::create([
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => $externalId,
                'title' => 'Product ' . $externalId,
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
            ]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'external_variant_id' => $externalId . '-v1',
                'title' => 'Default',
                'sku' => 'SKU-' . $externalId,
                'price' => 49.90,
                'position' => 0,
            ]);

            $plan = ProductSubscriptionPlan::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            ]);
            $plan->forceFill(['status' => ProductSubscriptionPlan::STATUS_ACTIVE])->save();

            return [$product, $variant, $plan];
        });
    }
}
