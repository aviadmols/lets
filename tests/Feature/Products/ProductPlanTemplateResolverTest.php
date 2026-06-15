<?php

namespace Tests\Feature\Products;

use App\Domain\Products\ProductPlanTemplateResolver;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The activation seam (ProductPlanTemplateResolver) returns the variant-specific
 * subscription template when present, else the product-wide (null-variant) one,
 * else null — and only the bound shop's templates are ever visible (fails closed).
 * It reads templates only; no money/ledger involvement.
 */
final class ProductPlanTemplateResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_resolves_variant_specific_plan_when_present(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        [$product, $variant] = $this->makeProductWithVariant($shop, '1001', '2001');

        $productWide = $this->makeActivePlan($shop, $product, null, 'Product-wide');
        $variantSpecific = $this->makeActivePlan($shop, $product, $variant, 'Variant-specific');

        $resolved = $this->resolver()->resolveDefaultsFor(
            $shop,
            'gid://shopify/Product/1001',
            'gid://shopify/ProductVariant/2001',
        );

        $this->assertNotNull($resolved);
        $this->assertSame($variantSpecific->id, $resolved->id);
        $this->assertNotSame($productWide->id, $resolved->id);
    }

    public function test_falls_back_to_product_wide_plan_when_no_variant_match(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        [$product, $variant] = $this->makeProductWithVariant($shop, '1001', '2001');

        $productWide = $this->makeActivePlan($shop, $product, null, 'Product-wide');
        // No variant-specific plan exists → resolver returns the product-wide one.

        $resolved = $this->resolver()->resolveDefaultsFor(
            $shop,
            'gid://shopify/Product/1001',
            'gid://shopify/ProductVariant/2001',
        );

        $this->assertNotNull($resolved);
        $this->assertSame($productWide->id, $resolved->id);
        $this->assertNull($resolved->product_variant_id);
    }

    public function test_returns_null_when_no_active_subscription_template(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        [$product, $variant] = $this->makeProductWithVariant($shop, '1001', '2001');

        // A DRAFT plan + a one-time plan exist but neither qualifies.
        $draft = $this->makeActivePlan($shop, $product, null, 'Draft');
        Tenant::run($shop, fn () => $draft->transitionTo(\App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus::DRAFT));
        Tenant::run($shop, function () use ($product): void {
            $oneTime = ProductSubscriptionPlan::create([
                'product_id' => $product->id,
                'plan_type' => ProductSubscriptionPlan::TYPE_ONE_TIME,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            ]);
            $oneTime->forceFill(['status' => ProductSubscriptionPlan::STATUS_ACTIVE])->save();
        });

        $resolved = $this->resolver()->resolveDefaultsFor($shop, 'gid://shopify/Product/1001', 'gid://shopify/ProductVariant/2001');

        $this->assertNull($resolved);
    }

    public function test_returns_null_for_unknown_product(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $resolved = $this->resolver()->resolveDefaultsFor($shop, 'gid://shopify/Product/9999');

        $this->assertNull($resolved);
    }

    public function test_is_tenant_scoped_another_shops_template_is_invisible(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        // Both shops have a product with the SAME external id 1001 — the unique
        // (shop_id, source, external_id) keeps them distinct; the resolver must
        // only ever see the passed shop's template.
        [$productA] = $this->makeProductWithVariant($shopA, '1001', '2001');
        [$productB] = $this->makeProductWithVariant($shopB, '1001', '2001');
        $planA = $this->makeActivePlan($shopA, $productA, null, 'Shop A plan');
        $planB = $this->makeActivePlan($shopB, $productB, null, 'Shop B plan');

        $resolvedForA = $this->resolver()->resolveDefaultsFor($shopA, 'gid://shopify/Product/1001');
        $this->assertSame($planA->id, $resolvedForA->id);

        $resolvedForB = $this->resolver()->resolveDefaultsFor($shopB, 'gid://shopify/Product/1001');
        $this->assertSame($planB->id, $resolvedForB->id);

        $this->assertNotSame($resolvedForA->id, $resolvedForB->id);
    }

    public function test_accepts_a_bare_external_id_not_only_a_gid(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        [$product] = $this->makeProductWithVariant($shop, '1001', '2001');
        $plan = $this->makeActivePlan($shop, $product, null, 'Product-wide');

        $resolved = $this->resolver()->resolveDefaultsFor($shop, '1001');

        $this->assertSame($plan->id, $resolved->id);
    }

    // === Helpers ===

    private function resolver(): ProductPlanTemplateResolver
    {
        return new ProductPlanTemplateResolver();
    }

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function makeProductWithVariant(Shop $shop, string $externalId, string $variantExternalId): array
    {
        return Tenant::run($shop, function () use ($externalId, $variantExternalId): array {
            $product = Product::create([
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => $externalId,
                'title' => 'Product ' . $externalId,
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
            ]);
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'external_variant_id' => $variantExternalId,
                'title' => 'Default',
                'sku' => 'SKU-' . $variantExternalId,
                'price' => 49.90,
                'position' => 0,
            ]);

            return [$product, $variant];
        });
    }

    private function makeActivePlan(Shop $shop, Product $product, ?ProductVariant $variant, string $name): ProductSubscriptionPlan
    {
        return Tenant::run($shop, function () use ($product, $variant, $name): ProductSubscriptionPlan {
            $plan = ProductSubscriptionPlan::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
                'plan_name' => $name,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            ]);
            $plan->forceFill(['status' => ProductSubscriptionPlan::STATUS_ACTIVE])->save();

            return $plan;
        });
    }
}
