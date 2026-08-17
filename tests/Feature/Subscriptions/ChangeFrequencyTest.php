<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Re-negotiating a subscription's cadence.
 *
 * The rule worth protecting is what the action does NOT do: it never moves the
 * next charge. A subscriber due on the 29th stays due on the 29th and the new
 * interval applies from the cycle after — because changing somebody's plan is
 * not a reason to take their money earlier than they were told.
 */
final class ChangeFrequencyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'frequency.example.com',
            'name' => 'Frequency',
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

    public function test_a_monthly_plan_can_be_moved_to_every_two_years(): void
    {
        $plan = $this->plan();
        $due = $plan->next_charge_at;

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('changeFrequency', [
                'interval_count' => 2,
                'billing_frequency' => BillingFrequency::YEARLY->value,
            ]);

        $plan->refresh();

        $this->assertSame(BillingFrequency::YEARLY, $plan->billing_frequency);
        $this->assertSame(2, (int) $plan->interval_count);
        $this->assertEquals($due, $plan->next_charge_at, 'the next charge date must not move');
    }

    public function test_every_x_months_is_expressible(): void
    {
        $plan = $this->plan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('changeFrequency', [
                'interval_count' => 3,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
            ]);

        $plan->refresh();

        $this->assertSame(BillingFrequency::MONTHLY, $plan->billing_frequency);
        $this->assertSame(3, (int) $plan->interval_count);
    }

    /** "Why is this billing yearly now" deserves an answer with a name on it. */
    public function test_the_change_is_written_to_the_timeline(): void
    {
        $plan = $this->plan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('changeFrequency', [
                'interval_count' => 6,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
            ]);

        $event = ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', Timeline::KIND_PLAN_EDITED)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('billing_frequency', $event->details['field'] ?? null);
        $this->assertSame('1 monthly', $event->details['was'] ?? null);
        $this->assertSame('6 monthly', $event->details['now'] ?? null);
    }

    /** A cadence belongs to a recurring plan; installments bill a fixed schedule. */
    public function test_the_action_is_hidden_on_an_installment_plan(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['plan_kind' => PlanKind::INSTALLMENTS->value])->save();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('changeFrequency');
    }

    public function test_the_action_is_hidden_once_the_plan_is_cancelled(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('changeFrequency');
    }

    private function plan(): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-freq-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 60,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(12)->startOfDay(),
        ])->save();

        return $plan;
    }
}
