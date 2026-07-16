<?php

namespace Tests\Feature\Products;

use App\Filament\Pages\ProductDetail;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Product DETAIL page + "Edit subscription plan" slide-over (Work Package W1,
 * Phase E). Proves: a missing/foreign product id REDIRECTS to the list (never a
 * 404/leak, mirroring FlowBuilder); the drawer loads + persists a per-variant
 * template with every value sanitized vs the model CONST allow-lists; shop_id +
 * status are NEVER written from input; a foreign plan id is a no-op; reorder
 * persists position; and "Add plan" authors a draft and opens the drawer.
 */
final class ProductDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'prod-detail.myshopify.com',
            'name' => 'Prod Detail',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'prod-detail@test.test',
            'password' => bcrypt('password'),
        ]));

        $this->product = $this->makeProduct($this->shop, '9001', 'Detail coffee');
        $this->variant = $this->makeVariant($this->shop, $this->product, '9001-v1', 100.00);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_detail_renders_the_product_and_its_variant_groups(): void
    {
        $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id);

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->assertOk()
            ->assertSee('Detail coffee')
            ->assertSee(__('products.detail.product_details'))
            ->assertSee(__('products.detail.add_subscription_plan'));
    }

    /**
     * The drawer summary read "₪73.26 every 2 year" — products.unit.* is singular-only and the
     * summary used a plain __(). It must pluralise via trans_choice (products.unit_choice.*).
     */
    public function test_the_price_summary_pluralises_the_unit(): void
    {
        $component = Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->set('frequencyUnit', 'yearly')
            ->set('intervalCount', 2);

        $summary = $component->instance()->planPriceSummary();

        $this->assertStringContainsString('years', $summary);
        $this->assertStringNotContainsString('2 year ', $summary.' ');

        // …and a single interval stays singular.
        $single = Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->set('frequencyUnit', 'yearly')
            ->set('intervalCount', 1)
            ->instance()
            ->planPriceSummary();

        $this->assertStringContainsString('year', $single);
        $this->assertStringNotContainsString('years', $single);
    }

    /** `biweekly` is ALREADY plural — a naive ":unit + s" would print "two weekss". */
    public function test_an_already_plural_unit_is_not_double_pluralised(): void
    {
        $summary = Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->set('frequencyUnit', 'biweekly')
            ->set('intervalCount', 3)
            ->instance()
            ->planPriceSummary();

        $this->assertStringNotContainsString('weekss', $summary);
    }

    /**
     * Regression guard for the 5-chevron bug: `.rc-pp-select` must be declared EXACTLY ONCE in
     * the published bundle. It was declared in BOTH post-purchase.css and products.css, and
     * because build-theme.mjs concatenates alphabetically the later `background:` shorthand
     * reset background-repeat → Filament's chevron TILED across the control.
     */
    public function test_the_select_skin_is_declared_exactly_once_in_the_bundle(): void
    {
        $css = (string) file_get_contents(public_path('css/rc-admin.css'));

        $this->assertSame(
            1,
            preg_match_all('/^\s*\.rc-pp-select\s*\{/m', $css),
            '.rc-pp-select must have ONE owner (post-purchase.css) — a second declaration silently wins the cascade.'
        );
    }

    public function test_missing_product_redirects_to_list_instead_of_404(): void
    {
        Livewire::test(ProductDetail::class, ['product' => 999999])
            ->assertRedirect(ProductResource::getUrl());
    }

    public function test_foreign_product_id_redirects_and_never_leaks(): void
    {
        // A second shop owns a product; bound to OUR shop, its id must not load.
        $other = Shop::create([
            'shopify_domain' => 'other-detail.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $foreign = $this->makeProduct($other, '9999', 'Foreign secret');

        Livewire::test(ProductDetail::class, ['product' => $foreign->id])
            ->assertRedirect(ProductResource::getUrl())
            ->assertDontSee('Foreign secret');
    }

    public function test_open_plan_config_loads_the_bound_fields(): void
    {
        $plan = $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id, [
            'plan_name' => 'Coffee club',
            'billing_frequency' => BillingFrequency::WEEKLY->value,
            'interval_count' => 4,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_PERCENT,
            'discount_value' => 15,
        ]);

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('openPlanConfig', $plan->id)
            ->assertSet('planDrawerOpen', true)
            ->assertSet('configPlanId', $plan->id)
            ->assertSet('planName', 'Coffee club')
            ->assertSet('intervalCount', 4)
            ->assertSet('frequencyUnit', BillingFrequency::WEEKLY->value)
            ->assertSet('offerDiscount', true)
            ->assertSet('discountPercent', 15)
            ->assertSee(__('products.plan_drawer.title'));
    }

    public function test_save_plan_config_persists_sanitized_per_variant_template(): void
    {
        $plan = $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id);

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('openPlanConfig', $plan->id)
            ->set('planName', 'Subscribe & save')
            ->set('intervalCount', 2)
            ->set('frequencyUnit', BillingFrequency::MONTHLY->value)
            ->set('offerDiscount', true)
            ->set('discountPercent', 25)
            ->set('chargeDayOfMonth', 15)
            ->set('expireEnabled', true)
            ->set('expireAfterCharges', 6)
            ->set('channels', [
                ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET,
                ProductSubscriptionPlan::CHANNEL_CUSTOMER_PORTAL,
                'bogus_channel', // must be filtered out by the allow-list
            ])
            ->call('savePlanConfig')
            ->assertSet('planDrawerOpen', false);

        $plan->refresh();
        $this->assertSame('Subscribe & save', $plan->plan_name);
        $this->assertSame(2, $plan->interval_count);
        $this->assertSame(BillingFrequency::MONTHLY, $plan->billing_frequency);
        $this->assertSame(ProductSubscriptionPlan::DISCOUNT_PERCENT, $plan->discount_type);
        $this->assertSame(25, (int) round((float) $plan->discount_value));
        $this->assertSame(15, $plan->charge_day_of_month);
        $this->assertSame(6, $plan->expire_after_charges);
        // The bogus channel was dropped; only allow-listed channels persist.
        $this->assertEqualsCanonicalizing([
            ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET,
            ProductSubscriptionPlan::CHANNEL_CUSTOMER_PORTAL,
        ], $plan->channels);
    }

    public function test_save_plan_config_clamps_discount_and_interval(): void
    {
        $plan = $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id);

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('openPlanConfig', $plan->id)
            ->set('offerDiscount', true)
            ->set('discountPercent', 250)  // > 100 → clamps to 100
            ->set('intervalCount', 0)      // < 1 → clamps to 1
            ->call('savePlanConfig');

        $plan->refresh();
        $this->assertSame(100, (int) round((float) $plan->discount_value));
        $this->assertSame(1, $plan->interval_count);
    }

    public function test_save_plan_config_never_changes_shop_id_or_status(): void
    {
        $plan = $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id);
        $originalShopId = $plan->shop_id;
        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->status);

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('openPlanConfig', $plan->id)
            ->set('planName', 'Edited')
            ->call('savePlanConfig');

        $plan->refresh();
        // shop_id untouched; status stays DRAFT (only transitionTo() flips it).
        $this->assertSame($originalShopId, $plan->shop_id);
        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->status);
    }

    public function test_save_plan_config_is_a_noop_for_a_foreign_plan(): void
    {
        // A second shop owns a plan; bound to OUR shop, saving its id must not write.
        $other = Shop::create([
            'shopify_domain' => 'other-plan.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $foreignPlanId = Tenant::run($other, function () use ($other): int {
            $product = $this->makeProduct($other, '9100', 'Foreign product');
            $variant = $this->makeVariant($other, $product, '9100-v1', 50.0);

            return $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $variant->id, [
                'plan_name' => 'Foreign plan',
                'shop_id' => $other->id,
                'product_id' => $product->id,
            ])->id;
        });

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->set('configPlanId', $foreignPlanId)
            ->set('planName', 'hacked')
            ->call('savePlanConfig');

        $foreign = Tenant::run($other, fn () => ProductSubscriptionPlan::findOrFail($foreignPlanId));
        $this->assertSame('Foreign plan', $foreign->plan_name);
    }

    public function test_toggle_plan_status_activates_a_draft_then_sets_it_back(): void
    {
        $plan = $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id);
        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->status);

        $component = Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('togglePlanStatus', $plan->id);
        $this->assertSame(PlanTemplateStatus::ACTIVE, $plan->fresh()->status);

        // …and toggles back to draft (the guarded transition allows both directions).
        $component->call('togglePlanStatus', $plan->id);
        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->fresh()->status);
    }

    public function test_toggle_plan_status_is_a_noop_for_a_foreign_plan(): void
    {
        $other = Shop::create([
            'shopify_domain' => 'other-toggle.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $foreignPlanId = Tenant::run($other, function () use ($other): int {
            $product = $this->makeProduct($other, '9200', 'Foreign product');
            $variant = $this->makeVariant($other, $product, '9200-v1', 50.0);

            return $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $variant->id, [
                'shop_id' => $other->id,
                'product_id' => $product->id,
            ])->id;
        });

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('togglePlanStatus', $foreignPlanId);

        // Bound to OUR shop, a foreign plan id must not flip — it stays DRAFT.
        $foreign = Tenant::run($other, fn () => ProductSubscriptionPlan::findOrFail($foreignPlanId));
        $this->assertSame(PlanTemplateStatus::DRAFT, $foreign->status);
    }

    public function test_add_subscription_plan_creates_a_draft_and_opens_the_drawer(): void
    {
        $component = Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('addSubscriptionPlan', $this->variant->id)
            ->assertSet('planDrawerOpen', true);

        $plan = ProductSubscriptionPlan::query()
            ->where('product_id', $this->product->id)
            ->where('plan_type', ProductSubscriptionPlan::TYPE_SUBSCRIPTION)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->status);
        $this->assertSame($this->variant->id, $plan->product_variant_id);
        $this->assertSame($this->shop->id, $plan->shop_id);
        $component->assertSet('configPlanId', $plan->id);
    }

    public function test_reorder_persists_position_within_the_variant_group(): void
    {
        $first = $this->makePlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $this->variant->id, ['position' => 0]);
        $second = $this->makePlan(ProductSubscriptionPlan::TYPE_ONE_TIME, $this->variant->id, ['position' => 1]);

        Livewire::test(ProductDetail::class, ['product' => $this->product->id])
            ->call('movePlanDown', $first->id);

        // First (pos 0) moves down → swaps positions with second.
        $this->assertSame(1, $first->fresh()->position);
        $this->assertSame(0, $second->fresh()->position);
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

    private function makeVariant(Shop $shop, Product $product, string $externalVariantId, float $price): ProductVariant
    {
        return Tenant::run($shop, function () use ($shop, $product, $externalVariantId, $price): ProductVariant {
            $variant = new ProductVariant();
            $variant->forceFill([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
                'title' => 'Default',
                'sku' => 'SKU-' . $externalVariantId,
                'price' => $price,
                'position' => 0,
            ])->save();

            return $variant;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function makePlan(string $type, ?int $variantId, array $attributes = []): ProductSubscriptionPlan
    {
        $plan = new ProductSubscriptionPlan();
        $plan->forceFill(array_merge([
            'shop_id' => Tenant::id(),
            'product_id' => $this->product->id ?? ($attributes['product_id'] ?? null),
            'product_variant_id' => $variantId,
            'plan_type' => $type,
            'plan_kind' => 'recurring',
            'interval_count' => 1,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => 0,
            'channels' => [ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET],
            'status' => PlanTemplateStatus::DRAFT->value,
            'position' => 0,
        ], $attributes))->save();

        return $plan;
    }
}
