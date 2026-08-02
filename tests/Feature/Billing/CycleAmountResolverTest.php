<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\CycleAmountResolver;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE single source of truth for a recurring cycle's amount (the pricing-modes
 * feature). The ladder: next-order override → stepped-up regular_amount past the
 * intro window → steady-state installment_amount. Charge ordinals count the
 * checkout as #1 (it occupies the succeeded seq-0 slot), so the slot sequence IS
 * the charge number — the boundary tests below pin that arithmetic.
 */
final class CycleAmountResolverTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS — the canonical windowed plan: ₪100 regular, ₪80 for 3 charges ===
    private const STEADY = 80.00;

    private const REGULAR = 100.00;

    private const WINDOW = 3;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'resolver.myshopify.com',
            'name' => 'Resolver',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_window_boundary_last_discounted_charge_then_step_up(): void
    {
        $plan = $this->windowedPlan();
        $resolver = new CycleAmountResolver;

        // Charges #2 and #3 (recurring cycles inside the window) keep the discount…
        $this->assertSame(self::STEADY, $resolver->amountForCharge($plan, 2));
        $this->assertSame(self::STEADY, $resolver->amountForCharge($plan, self::WINDOW));

        // …and charge #4 (the first past the window) bills the regular price.
        $this->assertSame(self::REGULAR, $resolver->amountForCharge($plan, self::WINDOW + 1));
    }

    public function test_the_next_order_override_wins_over_the_window(): void
    {
        $plan = $this->windowedPlan();
        $plan->forceFill(['meta' => array_merge((array) $plan->meta, [
            InstallmentPlan::META_NEXT_ORDER => [
                'line_items' => [['product_id' => 1, 'name' => 'Swap', 'quantity' => 1, 'unit_price' => 55.5]],
                'amount' => 55.5,
            ],
        ])])->save();

        // Even a past-the-window charge takes the override amount — the merchant's
        // one-time edit prices THIS cycle, whatever the schedule says.
        $this->assertSame(55.5, (new CycleAmountResolver)->amountForCharge($plan->fresh(), self::WINDOW + 5));
    }

    public function test_a_legacy_plan_with_null_snapshots_is_unchanged(): void
    {
        $plan = $this->makePlan(); // no regular_amount, no discount_cycles

        $this->assertSame(self::STEADY, (new CycleAmountResolver)->amountForCharge($plan, 99));
        $this->assertFalse((new CycleAmountResolver)->isDiscountedCycle($plan, 2));
        $this->assertNull((new CycleAmountResolver)->introWindowStatus($plan));
    }

    public function test_the_cycle_order_amount_prefers_the_frozen_succeeded_slot(): void
    {
        $plan = $this->windowedPlan();
        $this->succeededSlot($plan, 0, PaymentType::DEPOSIT, self::STEADY);
        // The slot amount is the money that MOVED — even if it disagrees with the
        // plan ladder (e.g. it was stamped before a later plan edit).
        $this->succeededSlot($plan, 2, PaymentType::RECURRING, 77.77);

        $this->assertSame(77.77, (new CycleAmountResolver)->amountForCycleOrder($plan));
    }

    public function test_the_charge_number_counts_the_checkout_as_one(): void
    {
        $plan = $this->windowedPlan();
        $this->succeededSlot($plan, 0, PaymentType::DEPOSIT, self::STEADY);

        // Checkout succeeded ⇒ the NEXT charge is #2 (the first recurring cycle).
        $this->assertSame(2, (new CycleAmountResolver)->chargeNumberForNext($plan));
    }

    public function test_the_discount_predicate_flips_exactly_at_the_window_edge(): void
    {
        $plan = $this->windowedPlan();
        $resolver = new CycleAmountResolver;

        $this->assertTrue($resolver->isDiscountedCycle($plan, self::WINDOW));
        $this->assertFalse($resolver->isDiscountedCycle($plan, self::WINDOW + 1));
    }

    public function test_a_kept_first_payment_below_catalog_counts_as_discounted(): void
    {
        // keep_first_payment with no window: installment_amount was written back
        // below regular_amount at activation — every cycle is a discounted cycle.
        $plan = $this->makePlan(regularAmount: self::REGULAR);

        $this->assertTrue((new CycleAmountResolver)->isDiscountedCycle($plan, 7));
    }

    public function test_the_intro_window_status_tracks_succeeded_charges(): void
    {
        $plan = $this->windowedPlan();
        $this->succeededSlot($plan, 0, PaymentType::DEPOSIT, self::STEADY);

        $this->assertSame(['used' => 1, 'total' => self::WINDOW], (new CycleAmountResolver)->introWindowStatus($plan));

        $this->succeededSlot($plan, 2, PaymentType::RECURRING, self::STEADY);
        $this->succeededSlot($plan, 3, PaymentType::RECURRING, self::STEADY);
        $this->succeededSlot($plan, 4, PaymentType::RECURRING, self::REGULAR);

        // Used caps at the window size even when charging continues past it.
        $this->assertSame(['used' => self::WINDOW, 'total' => self::WINDOW], (new CycleAmountResolver)->introWindowStatus($plan->fresh()));
    }

    // === Fixtures ===

    private function windowedPlan(): InstallmentPlan
    {
        return $this->makePlan(regularAmount: self::REGULAR, discountCycles: self::WINDOW);
    }

    private function makePlan(?float $regularAmount = null, ?int $discountCycles = null): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => self::STEADY,
            'total_charged' => 0,
            'installment_amount' => self::STEADY,
            'regular_amount' => $regularAmount,
            'discount_cycles' => $discountCycles,
            'currency' => 'ILS',
            'public_id' => 'PLAN-'.uniqid(),
            'customer_email' => 'buyer@example.com',
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan;
    }

    private function succeededSlot(InstallmentPlan $plan, int $sequence, PaymentType $type, float $amount): void
    {
        $payment = InstallmentPayment::create([
            'plan_id' => $plan->getKey(),
            'sequence' => $sequence,
            'payment_type' => $type->value,
            'amount' => $amount,
            'currency' => 'ILS',
        ]);
        $payment->forceFill(['status' => PaymentStatus::SUCCEEDED->value])->save();
    }
}
