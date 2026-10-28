<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\CheckoutPricingCapture;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkout pricing capture at activation: the coupon lands in META_CHECKOUT_DISCOUNT
 * (display + tagging only), and keep_first_payment writes the actually-paid line
 * amount back as the steady-state cycle amount — with ambiguity SKIPPING the
 * write-back (never charge a guessed amount on a saved token).
 */
final class CheckoutPricingCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'capture.myshopify.com',
            'name' => 'Capture',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Coupon capture ===

    public function test_shopify_discount_codes_land_in_plan_meta_with_a_timeline_row(): void
    {
        $plan = $this->makePlan();

        app(CheckoutPricingCapture::class)->apply($plan, [
            'discount_codes' => [['code' => 'SAVE20', 'amount' => '20.00', 'type' => 'percentage']],
            'total_discounts' => '20.00',
        ]);

        $discount = $plan->fresh()->checkoutDiscount();
        $this->assertSame(['SAVE20'], $discount['codes']);
        // The JSON meta round-trip may narrow 20.0 to int 20 — compare as float.
        $this->assertSame(20.0, (float) $discount['amount']);

        $this->assertTrue(ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED)
            ->exists());
    }

    public function test_woo_coupon_lines_land_in_plan_meta(): void
    {
        $plan = $this->makePlan();

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [['code' => 'welcome10', 'discount' => '10.00']],
        ]);

        $discount = $plan->fresh()->checkoutDiscount();
        $this->assertSame(['welcome10'], $discount['codes']);
        $this->assertSame(10.0, (float) $discount['amount']);
    }

    public function test_an_order_without_coupons_stores_nothing(): void
    {
        $plan = $this->makePlan();

        app(CheckoutPricingCapture::class)->apply($plan, ['total_price' => '80.00']);

        $this->assertNull($plan->fresh()->checkoutDiscount());
    }

    // === keep_first_payment write-back ===

    public function test_a_matching_shopify_line_writes_the_discounted_amount_back(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_KEEP_FIRST, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'line_items' => [
                ['variant_id' => 444, 'price' => '100.00', 'quantity' => 1, 'discount_allocations' => [['amount' => '25.00']]],
                ['variant_id' => 999, 'price' => '10.00', 'quantity' => 2],
            ],
            'total_price' => '95.00',
        ]);

        // 100 − 25 allocated = the price the shopper actually paid for THIS item.
        $this->assertSame(75.0, (float) $plan->fresh()->installment_amount);
        // regular_amount is untouched — it powers the discount-tag predicate.
        $this->assertSame(100.0, (float) $plan->fresh()->regular_amount);
    }

    public function test_a_woo_line_uses_total_plus_tax(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_KEEP_FIRST, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'line_items' => [
                ['variation_id' => 444, 'product_id' => 7, 'total' => '59.83', 'total_tax' => '10.17', 'quantity' => 1],
            ],
        ]);

        $this->assertSame(70.0, (float) $plan->fresh()->installment_amount);
    }

    public function test_ambiguous_attribution_skips_the_write_back(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_KEEP_FIRST, variantId: '444');

        // TWO lines sell the plan's variant — attribution is a guess; keep the
        // template-derived amount.
        app(CheckoutPricingCapture::class)->apply($plan, [
            'line_items' => [
                ['variant_id' => 444, 'price' => '90.00', 'quantity' => 1],
                ['variant_id' => 444, 'price' => '80.00', 'quantity' => 1],
            ],
        ]);

        $this->assertSame(80.0, (float) $plan->fresh()->installment_amount);
    }

    public function test_other_pricing_modes_never_write_back(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'line_items' => [['variant_id' => 444, 'price' => '60.00', 'quantity' => 1]],
        ]);

        $this->assertSame(80.0, (float) $plan->fresh()->installment_amount);
    }

    public function test_a_single_line_order_without_a_variant_match_still_attributes(): void
    {
        // The plan predates variant capture (no external ids) but the order has
        // exactly one line — unambiguous.
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_KEEP_FIRST);

        app(CheckoutPricingCapture::class)->apply($plan, [
            'line_items' => [['price' => '64.00', 'quantity' => 1, 'total_discount' => '4.00']],
        ]);

        $this->assertSame(60.0, (float) $plan->fresh()->installment_amount);
    }

    // === WooCommerce coupon scope (class-lets-coupons.php) ===

    public function test_an_all_cycles_coupon_keeps_the_paid_amount_whatever_the_pricing_mode(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('forever10', 'all_cycles', 'percent', '10')],
            'line_items' => [
                ['variation_id' => 444, 'product_id' => 7, 'total' => '72.00', 'total_tax' => '0', 'quantity' => 1],
                ['variation_id' => 999, 'product_id' => 8, 'total' => '30.00', 'total_tax' => '0', 'quantity' => 1],
            ],
        ]);

        $fresh = $plan->fresh();
        $this->assertSame(72.0, (float) $fresh->installment_amount);
        $this->assertSame('all_cycles', $fresh->checkoutDiscount()['scope']);
        // regular_amount stays the undiscounted price — the discount tag still fires.
        $this->assertSame(100.0, (float) $fresh->regular_amount);
    }

    public function test_a_first_cycle_coupon_changes_nothing_but_is_remembered(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('once10', 'first_cycle', 'percent', '10')],
            'line_items' => [
                ['variation_id' => 444, 'product_id' => 7, 'total' => '72.00', 'total_tax' => '0', 'quantity' => 1],
            ],
        ]);

        $fresh = $plan->fresh();
        $this->assertSame(80.0, (float) $fresh->installment_amount);
        $this->assertSame('first_cycle', $fresh->checkoutDiscount()['scope']);
    }

    public function test_a_coupon_without_a_scope_is_treated_as_first_cycle(): void
    {
        // Older plugin: coupon_lines carry no meta — today's behavior, no write-back.
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [['code' => 'legacy', 'discount' => '8.00']],
            'line_items' => [['variation_id' => 444, 'product_id' => 7, 'total' => '72.00', 'quantity' => 1]],
        ]);

        $fresh = $plan->fresh();
        $this->assertSame(80.0, (float) $fresh->installment_amount);
        $this->assertNull($fresh->checkoutDiscount()['scope']);
    }

    public function test_an_ambiguous_order_still_honors_a_percent_all_cycles_coupon(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');

        // Two lines sell the variant: line attribution is a guess, the percent is not.
        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('forever25', 'all_cycles', 'percent', '25')],
            'line_items' => [
                ['variation_id' => 444, 'product_id' => 7, 'total' => '60.00', 'quantity' => 1],
                ['variation_id' => 444, 'product_id' => 7, 'total' => '60.00', 'quantity' => 1],
            ],
        ]);

        $this->assertSame(60.0, (float) $plan->fresh()->installment_amount);
    }

    public function test_an_ambiguous_order_skips_a_fixed_all_cycles_coupon(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('minus20', 'all_cycles', 'fixed_cart', '20')],
            'line_items' => [
                ['variation_id' => 444, 'product_id' => 7, 'total' => '70.00', 'quantity' => 1],
                ['variation_id' => 444, 'product_id' => 7, 'total' => '70.00', 'quantity' => 1],
            ],
        ]);

        $this->assertSame(80.0, (float) $plan->fresh()->installment_amount);
    }

    public function test_an_all_cycles_coupon_discounts_the_step_up_price_of_an_intro_window(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');
        $plan->forceFill(['discount_cycles' => 3])->save();

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('forever10', 'all_cycles', 'percent', '10')],
            'line_items' => [['variation_id' => 444, 'product_id' => 7, 'total' => '72.00', 'quantity' => 1]],
        ]);

        $fresh = $plan->fresh();
        $this->assertSame(72.0, (float) $fresh->installment_amount);
        // 100 × (72 / 80) — the post-window price carries the same 10%.
        $this->assertSame(90.0, (float) $fresh->regular_amount);
    }

    public function test_an_all_cycles_coupon_gives_a_plan_without_a_regular_amount_one(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_PLAN_PRICE, variantId: '444');
        $plan->forceFill(['regular_amount' => null])->save();

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('forever10', 'all_cycles', 'percent', '10')],
            'line_items' => [['variation_id' => 444, 'product_id' => 7, 'total' => '72.00', 'quantity' => 1]],
        ]);

        $fresh = $plan->fresh();
        $this->assertSame(72.0, (float) $fresh->installment_amount);
        $this->assertSame(80.0, (float) $fresh->regular_amount);
    }

    public function test_keep_first_payment_is_not_written_twice_by_an_all_cycles_coupon(): void
    {
        $plan = $this->makePlan(pricingMode: ProductSubscriptionPlan::PRICING_KEEP_FIRST, variantId: '444');

        app(CheckoutPricingCapture::class)->apply($plan, [
            'coupon_lines' => [$this->wooCouponLine('forever10', 'all_cycles', 'percent', '10')],
            'line_items' => [['variation_id' => 444, 'product_id' => 7, 'total' => '72.00', 'quantity' => 1]],
        ]);

        $fresh = $plan->fresh();
        $this->assertSame(72.0, (float) $fresh->installment_amount);
        $this->assertSame(100.0, (float) $fresh->regular_amount);
    }

    // === Fixtures ===

    /** A WC REST coupon line as the plugin stamps it (class-lets-coupons.php). */
    private function wooCouponLine(string $code, string $scope, string $type, string $amount): array
    {
        return [
            'code' => $code,
            'discount' => '8.00',
            'meta_data' => [
                ['id' => 1, 'key' => 'lets_subscription_scope', 'value' => $scope],
                ['id' => 2, 'key' => 'lets_coupon_type', 'value' => $type],
                ['id' => 3, 'key' => 'lets_coupon_amount', 'value' => $amount],
            ],
        ];
    }

    private function makePlan(?string $pricingMode = null, ?string $variantId = null): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 80,
            'total_charged' => 0,
            'installment_amount' => 80,
            'regular_amount' => 100,
            'pricing_mode' => $pricingMode,
            'currency' => 'ILS',
            'public_id' => 'PLAN-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'shopify_variant_id' => $variantId,
            'external_variant_id' => $variantId,
        ]);
        $plan->forceFill(['status' => PlanStatus::AWAITING_FIRST_PAYMENT->value])->save();

        return $plan;
    }
}
