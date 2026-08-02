<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\SellingPlanService;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Shopify-Payments rail's pricing policies per template pricing mode:
 * fixed_amount → a PRICE policy; a windowed discount → the fixed discount PLUS a
 * zero-adjustment `recurring` policy with afterCycle (Shopify itself steps the
 * price back up); an open discount → the single fixed policy (unchanged);
 * keep_first_payment → no policy (no Shopify primitive; the drawer blocks it).
 */
final class SellingPlanPricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'policies.myshopify.com',
            'name' => 'Policies',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_windowed_discount_emits_fixed_plus_recurring_after_cycle(): void
    {
        $policies = $this->policiesFor([
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_PERCENT,
            'discount_value' => 20,
            'discount_cycles' => 3,
        ]);

        $this->assertCount(2, $policies);
        $this->assertSame(20.0, (float) $policies[0]['fixed']['adjustmentValue']['percentage']);
        $this->assertSame('PERCENTAGE', $policies[1]['recurring']['adjustmentType']);
        $this->assertSame(0.0, (float) $policies[1]['recurring']['adjustmentValue']['percentage']);
        $this->assertSame(3, $policies[1]['recurring']['afterCycle']);
    }

    public function test_an_open_discount_stays_a_single_fixed_policy(): void
    {
        $policies = $this->policiesFor([
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_PERCENT,
            'discount_value' => 15,
        ]);

        $this->assertCount(1, $policies);
        $this->assertArrayHasKey('fixed', $policies[0]);
    }

    public function test_fixed_amount_mode_emits_a_price_policy(): void
    {
        $policies = $this->policiesFor([
            'pricing_mode' => ProductSubscriptionPlan::PRICING_FIXED,
            'fixed_cycle_amount' => 49.90,
        ]);

        $this->assertCount(1, $policies);
        $this->assertSame('PRICE', $policies[0]['fixed']['adjustmentType']);
        $this->assertSame(49.9, (float) $policies[0]['fixed']['adjustmentValue']['fixedValue']);
    }

    public function test_keep_first_payment_emits_no_policy(): void
    {
        // No Shopify-Payments primitive exists for "keep what was paid" — the
        // template behaves as plan_price there, and without a discount that
        // means no policy at all.
        $policies = $this->policiesFor([
            'pricing_mode' => ProductSubscriptionPlan::PRICING_KEEP_FIRST,
        ]);

        $this->assertSame([], $policies);
    }

    public function test_a_window_without_a_discount_is_ignored(): void
    {
        $policies = $this->policiesFor(['discount_cycles' => 3]);

        $this->assertSame([], $policies);
    }

    // === Helpers ===

    /** @param array<string, mixed> $attributes */
    private function policiesFor(array $attributes): array
    {
        $template = new ProductSubscriptionPlan;
        $template->forceFill(array_merge([
            'shop_id' => $this->shop->getKey(),
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'plan_kind' => 'recurring',
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => 0,
            'pricing_mode' => ProductSubscriptionPlan::PRICING_PLAN_PRICE,
        ], $attributes));

        $method = new ReflectionMethod(SellingPlanService::class, 'pricingPolicies');

        return $method->invoke(app(SellingPlanService::class), $template);
    }
}
