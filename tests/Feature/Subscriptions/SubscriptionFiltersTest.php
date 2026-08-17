<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\InstallmentPaymentMethod;
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
 * Finding the subscriptions that need a human.
 *
 * The card filter is the one that earns its keep: a plan can be perfectly active
 * and completely uncollectable because the card behind it expired, and that is a
 * list a merchant wants BEFORE the cycle fails rather than after. A card is good
 * to the END of its expiry month, which is the boundary these pin down.
 */
final class SubscriptionFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'filters.example.com',
            'name' => 'Filters',
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

    public function test_the_card_filter_separates_expired_from_expiring_from_valid(): void
    {
        $expired = $this->planWithCard('expired', now()->subMonths(2));
        $expiringThisMonth = $this->planWithCard('this-month', now());
        $valid = $this->planWithCard('valid', now()->addYears(2));
        $noCard = $this->plan('no-card');

        // Expired: strictly before the current month. A card expiring THIS month
        // is still chargeable today, so it must not be in this list.
        $this->assertFilter('card_status', 'expired', [$expired]);

        // Expiring soon: inside the window, but not yet dead.
        $this->assertFilter('card_status', 'expiring', [$expiringThisMonth]);

        $this->assertFilter('card_status', 'none', [$noCard]);

        $shown = $this->filtered('card_status', 'valid');
        $this->assertContains($valid->id, $shown);
        $this->assertNotContains($expired->id, $shown);
    }

    public function test_the_charge_date_filter_narrows_to_a_single_day(): void
    {
        $today = $this->plan('today', now()->startOfDay());
        $tomorrow = $this->plan('tomorrow', now()->addDay()->startOfDay());
        $never = $this->plan('never', null);

        $shown = Livewire::test(ListSubscriptions::class)
            ->filterTable('next_charge_at', [
                'from' => now()->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$tomorrow, $never]);

        $this->assertNotNull($shown);
    }

    /** The window is a constant, and the boundary belongs to the merchant's calendar. */
    public function test_a_card_beyond_the_window_is_not_expiring_yet(): void
    {
        $far = $this->planWithCard('far', now()->addMonths(SubscriptionResource::CARD_EXPIRING_MONTHS + 3));

        $this->assertNotContains($far->id, $this->filtered('card_status', 'expiring'));
    }

    // === Helpers ===

    /** @return list<int> the ids the table shows under this filter */
    private function filtered(string $filter, string $value): array
    {
        $component = Livewire::test(ListSubscriptions::class)->filterTable($filter, $value);

        return $component->instance()->getFilteredTableQuery()->pluck('id')->all();
    }

    /** @param list<InstallmentPlan> $expected */
    private function assertFilter(string $filter, string $value, array $expected): void
    {
        $shown = $this->filtered($filter, $value);

        $this->assertSame(
            collect($expected)->pluck('id')->sort()->values()->all(),
            collect($shown)->sort()->values()->all(),
            "filter {$filter}={$value}",
        );
    }

    private function plan(string $ref, mixed $nextCharge = null): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.$ref,
            'customer_name' => $ref,
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => $nextCharge,
        ])->save();

        return $plan;
    }

    private function planWithCard(string $ref, \DateTimeInterface $expires): InstallmentPlan
    {
        $method = InstallmentPaymentMethod::create([
            'payplus_card_token_uid' => 'tok-'.$ref,
            'card_brand' => 'visa',
            'card_last_four' => '4242',
            'exp_month' => (int) $expires->format('n'),
            'exp_year' => (int) $expires->format('Y'),
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);

        $plan = $this->plan($ref, now()->addDays(5));
        $plan->forceFill(['payment_method_id' => $method->id])->save();

        return $plan;
    }
}
