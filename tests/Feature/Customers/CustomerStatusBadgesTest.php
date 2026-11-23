<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\Customers;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The customers list says each customer's state in WORDS, and lists only people who
 * actually bought. A coloured dot made a paying customer look like a problem the
 * moment an abandoned checkout of theirs sat "awaiting first payment" beside it.
 */
final class CustomerStatusBadgesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'badges.example.com',
            'name' => 'Badges',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** An abandoned checkout is not a customer: nobody paid. */
    public function test_someone_whose_only_plan_awaits_its_first_payment_is_not_listed(): void
    {
        $this->plan('never@example.com', 'Never Paid', PlanStatus::AWAITING_FIRST_PAYMENT);

        $this->assertCount(0, $this->rows());
    }

    /** A paying customer's leftover abandoned checkout no longer paints them as a problem. */
    public function test_a_leftover_unpaid_checkout_does_not_change_a_paying_customers_status(): void
    {
        $this->plan('dana@example.com', 'Dana', PlanStatus::ACTIVE);
        $this->plan('dana@example.com', 'Dana', PlanStatus::AWAITING_FIRST_PAYMENT);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame([['key' => 'active', 'tone' => 'green']], $rows->first()['statuses']);
    }

    /** An IMPORTED pending/trial member was brought in on purpose — they stay. */
    public function test_an_imported_member_awaiting_a_first_payment_stays_listed(): void
    {
        $this->plan('trial@example.com', 'Trial Member', PlanStatus::AWAITING_FIRST_PAYMENT, meta: ['import' => ['status' => 'trial']]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame([['key' => 'awaiting_first_payment', 'tone' => 'amber']], $rows->first()['statuses']);
    }

    /** Two subscriptions in two states: both said, the one that needs attention first. */
    public function test_every_live_status_is_shown_most_urgent_first(): void
    {
        $this->plan('noa@example.com', 'Noa', PlanStatus::ACTIVE);
        $this->plan('noa@example.com', 'Noa', PlanStatus::AWAITING_PAYMENT);

        $this->assertSame(
            [['key' => 'awaiting_payment', 'tone' => 'amber'], ['key' => 'active', 'tone' => 'green']],
            $this->rows()->first()['statuses'],
        );
    }

    public function test_a_customer_with_nothing_live_reads_inactive(): void
    {
        $this->plan('gone@example.com', 'Gone', PlanStatus::CANCELLED);

        $this->assertSame([['key' => 'inactive', 'tone' => 'gray']], $this->rows()->first()['statuses']);
    }

    // === Helpers ===

    private function rows()
    {
        return Livewire::test(Customers::class)->instance()->customers();
    }

    private function plan(string $email, string $name, PlanStatus $status, array $meta = []): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'customer_name' => $name,
            'customer_email' => $email,
            'total_amount' => 50,
            'total_charged' => 0,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'public_id' => 'PLAN-'.uniqid(),
        ]);
        $plan->forceFill(['status' => $status->value, 'meta' => $meta === [] ? null : $meta])->save();

        return $plan;
    }
}
