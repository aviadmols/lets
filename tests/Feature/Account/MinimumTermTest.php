<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The minimum term: how many cycles a subscriber pays before THEY may pause or
 * cancel.
 *
 * Three rules are worth protecting. It withholds only the two ways OUT — a
 * commitment that also blocked "resume" could trap somebody in a paused
 * subscription, and one that blocked "skip" would turn a postponement into an
 * escape. It is read from the PLAN's own snapshot, so raising the template's
 * minimum re-negotiates nothing that is already sold. And the cycles a migrated
 * member paid in the system they came from COUNT — telling somebody who has
 * subscribed since 2015 that they owe three more payments would be false.
 */
final class MinimumTermTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'term.example.com',
            'name' => 'Term',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_plan_without_a_commitment_can_always_be_ended(): void
    {
        $plan = $this->plan(minCycles: null, paid: 0);

        $this->assertTrue($plan->customerMayExit());
        $this->assertSame(0, $plan->cyclesUntilExit());
        $this->assertTrue($this->actions()->availableFor($plan)[CustomerSubscriptionActions::ACTION_CANCEL]);
    }

    public function test_the_exits_are_withheld_until_the_term_is_paid(): void
    {
        $plan = $this->plan(minCycles: 3, paid: 1);
        $available = $this->actions()->availableFor($plan);

        $this->assertSame(2, $plan->cyclesUntilExit());
        $this->assertFalse($available[CustomerSubscriptionActions::ACTION_CANCEL]);
        $this->assertFalse($available[CustomerSubscriptionActions::ACTION_PAUSE]);

        // Postponing is not leaving, and the plan is still billable.
        $this->assertTrue($available[CustomerSubscriptionActions::ACTION_SKIP]);
        $this->assertTrue($available[CustomerSubscriptionActions::ACTION_RESCHEDULE]);
    }

    public function test_the_last_payment_of_the_term_opens_the_exits(): void
    {
        $plan = $this->plan(minCycles: 3, paid: 3);

        $this->assertSame(0, $plan->cyclesUntilExit());
        $this->assertTrue($this->actions()->availableFor($plan)[CustomerSubscriptionActions::ACTION_CANCEL]);
    }

    /** A hidden button is a hint; the endpoint is reachable without one. */
    public function test_the_server_refuses_a_cancel_inside_the_term(): void
    {
        $plan = $this->plan(minCycles: 3, paid: 1);

        $result = $this->actions()->perform($this->visitor(), CustomerSubscriptionActions::ACTION_CANCEL, (string) $plan->public_id);

        $this->assertSame(CustomerSubscriptionActions::RESULT_NOT_ALLOWED, $result['result']);
        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    public function test_the_server_refuses_a_pause_inside_the_term(): void
    {
        $plan = $this->plan(minCycles: 3, paid: 1);

        $result = $this->actions()->perform($this->visitor(), CustomerSubscriptionActions::ACTION_PAUSE, (string) $plan->public_id);

        $this->assertSame(CustomerSubscriptionActions::RESULT_NOT_ALLOWED, $result['result']);
        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    /**
     * A paused subscriber must always be able to come back — otherwise a
     * commitment becomes a trap rather than a term.
     */
    public function test_resuming_is_never_withheld(): void
    {
        $plan = $this->plan(minCycles: 6, paid: 1);
        $plan->forceFill(['status' => PlanStatus::PAUSED->value])->save();

        $this->assertTrue($this->actions()->availableFor($plan)[CustomerSubscriptionActions::ACTION_RESUME]);
    }

    /** Eleven years elsewhere are eleven years. */
    public function test_cycles_paid_before_the_migration_count_towards_the_term(): void
    {
        $plan = $this->plan(minCycles: 3, paid: 0);
        $plan->forceFill(['meta' => ['import' => ['history' => ['charges_succeeded' => 11]]]])->save();

        $plan = $plan->fresh();

        $this->assertSame(11, $plan->paidCycles());
        $this->assertTrue($plan->customerMayExit());
    }

    /** A failed attempt is not a cycle the customer paid for. */
    public function test_a_failed_charge_does_not_count(): void
    {
        $plan = $this->plan(minCycles: 2, paid: 1);
        $this->payment($plan, 2, PaymentStatus::FAILED);

        $this->assertSame(1, $plan->fresh()->paidCycles());
        $this->assertFalse($plan->fresh()->customerMayExit());
    }

    private function actions(): CustomerSubscriptionActions
    {
        return app(CustomerSubscriptionActions::class);
    }

    private function visitor(): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $this->shop,
            customerRef: '77',
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
        );
    }

    private function plan(?int $minCycles, int $paid): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'public_id' => 'PLN-term-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'shopify_customer_id' => '77',
            'customer_email' => 'member@example.com',
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10),
            'min_cycles_before_exit' => $minCycles,
        ])->save();

        for ($i = 1; $i <= $paid; $i++) {
            $this->payment($plan, $i, PaymentStatus::SUCCEEDED);
        }

        return $plan->fresh();
    }

    private function payment(InstallmentPlan $plan, int $sequence, PaymentStatus $status): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'plan_id' => $plan->getKey(),
            'payment_type' => PaymentType::RECURRING->value,
            'sequence' => $sequence,
            'amount' => 59,
            'currency' => 'ILS',
            'status' => $status->value,
        ])->save();
    }
}
