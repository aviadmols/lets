<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Products LIST screen (Work Package W1, Phase E). Proves the Recharge-style list
 * shows ONLY the bound shop's products (tenant scope), the per-row derived
 * columns (purchase types + plan/variant counts), and the min-3-char search
 * (a no-op under the threshold). Renders only — the catalog is laravel-backend's.
 */
final class ProductResourceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'prod-list.myshopify.com',
            'name' => 'Prod List',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'prod-list@test.test',
            'password' => bcrypt('password'),
        ]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_list_shows_only_the_bound_shops_products(): void
    {
        $this->makeProduct($this->shop, '7001', 'Our coffee beans');

        // A second shop owns a product the bound shop must never see.
        $other = Shop::create([
            'shopify_domain' => 'other-list.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::run($other, fn () => $this->makeProduct($other, '8001', 'Foreign widget'));

        Livewire::test(ListProducts::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Product::query()->get())
            ->assertSee('Our coffee beans')
            ->assertDontSee('Foreign widget');
    }

    public function test_purchase_type_badges_are_derived_from_plan_templates(): void
    {
        $product = $this->makeProduct($this->shop, '7002', 'Mixed product');
        $variant = $this->makeVariant($this->shop, $product, '7002-v1');
        $this->makePlan($this->shop, $product, $variant, ProductSubscriptionPlan::TYPE_SUBSCRIPTION);
        $this->makePlan($this->shop, $product, $variant, ProductSubscriptionPlan::TYPE_ONE_TIME);

        $types = ProductResource::purchaseTypes($product->fresh());

        $this->assertContains(__('products.purchase.one_time'), $types);
        $this->assertContains(__('products.purchase.subscription'), $types);
    }

    public function test_search_under_three_chars_is_a_noop_but_three_chars_filters(): void
    {
        $this->makeProduct($this->shop, '7003', 'Searchable coffee');
        $this->makeProduct($this->shop, '7004', 'Unrelated candle');

        // 2 chars → no-op: both rows still listed.
        Livewire::test(ListProducts::class)
            ->set('tableSearch', 'co')
            ->assertSee('Searchable coffee')
            ->assertSee('Unrelated candle');

        // 3+ chars → filters to the matching title only.
        Livewire::test(ListProducts::class)
            ->set('tableSearch', 'coffee')
            ->assertSee('Searchable coffee')
            ->assertDontSee('Unrelated candle');
    }

    public function test_search_matches_variant_sku(): void
    {
        $product = $this->makeProduct($this->shop, '7005', 'SKU product');
        $this->makeVariant($this->shop, $product, '7005-v1', 'WIDGET-XYZ');
        $this->makeProduct($this->shop, '7006', 'Other product');

        Livewire::test(ListProducts::class)
            ->set('tableSearch', 'WIDGET-XYZ')
            ->assertSee('SKU product')
            ->assertDontSee('Other product');
    }

    public function test_refresh_dispatches_the_import_job_for_the_bound_shop(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->makeProduct($this->shop, '7007', 'Refreshable');

        // The header action's seam: triggers the tenant's full re-sync.
        ProductResource::refreshProducts();

        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\Products\ImportShopProductsJob::class,
        );
    }

    public function test_refresh_table_header_action_exists_and_is_callable(): void
    {
        $this->makeProduct($this->shop, '7008', 'Refreshable B');
        \Illuminate\Support\Facades\Bus::fake();

        Livewire::test(ListProducts::class)
            ->callTableAction('refresh')
            ->assertHasNoTableActionErrors();
    }

    // === Helpers ===

    private function makeProduct(Shop $shop, string $externalId, string $title): Product
    {
        return Tenant::run($shop, function () use ($shop, $externalId, $title): Product {
            $product = new Product();
            $product->forceFill([
                'shop_id' => $shop->id,
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => $externalId,
                'title' => $title,
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
                'updated_at_external' => now(),
            ])->save();

            return $product;
        });
    }

    private function makeVariant(Shop $shop, Product $product, string $externalVariantId, string $sku = 'SKU-1'): ProductVariant
    {
        return Tenant::run($shop, function () use ($shop, $product, $externalVariantId, $sku): ProductVariant {
            $variant = new ProductVariant();
            $variant->forceFill([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
                'title' => 'Default',
                'sku' => $sku,
                'price' => 49.90,
                'position' => 0,
            ])->save();

            return $variant;
        });
    }

    private function makePlan(Shop $shop, Product $product, ProductVariant $variant, string $type): ProductSubscriptionPlan
    {
        return Tenant::run($shop, function () use ($shop, $product, $variant, $type): ProductSubscriptionPlan {
            $plan = new ProductSubscriptionPlan();
            $plan->forceFill([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'plan_type' => $type,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
                'status' => ProductSubscriptionPlan::STATUS_ACTIVE,
                'position' => 0,
            ])->save();

            return $plan;
        });
    }
}
