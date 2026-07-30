<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\HomeDashboard;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reading the dashboard over a day, a week or a month.
 *
 * The numbers were fixed to the last 30 days, which answers "how is the month
 * going" and nothing else. The comparison column moves with the choice — weekly
 * compares this week with LAST week, not with a month whose totals would dwarf it
 * and make every week look like a collapse.
 */
final class DashboardPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'period.example.com',
            'name' => 'Period',
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

    public function test_it_opens_on_the_month(): void
    {
        Livewire::test(HomeDashboard::class)
            ->assertSet('range', 'monthly')
            ->assertSee(__('dashboard.performance.range.weekly'))
            ->assertSee(__('dashboard.performance.range.daily'));
    }

    public function test_choosing_a_period_changes_what_the_numbers_cover(): void
    {
        // ₪100 three days ago: inside the month and the week, outside today.
        $this->revenue(100.00, daysAgo: 3);

        $page = Livewire::test(HomeDashboard::class);

        $this->assertSame(100.0, $this->processed($page->instance()));

        $page->call('selectRange', 'weekly');
        $this->assertSame(100.0, $this->processed($page->instance()));

        $page->call('selectRange', 'daily');
        // A day-long window does not reach back three days.
        $this->assertSame(0.0, $this->processed($page->instance()));
    }

    public function test_an_unknown_period_reads_as_the_default_not_as_zero_days(): void
    {
        $this->revenue(100.00, daysAgo: 3);

        // The value arrives from the browser. A zero-day window would empty every
        // number on the page and look like the shop had stopped trading.
        $page = Livewire::test(HomeDashboard::class)->set('range', 'fortnightly');

        $this->assertSame(HomeDashboard::DEFAULT_RANGE_DAYS, $page->instance()->rangeDays());
        $this->assertSame(100.0, $this->processed($page->instance()));
    }

    public function test_a_bogus_period_from_the_browser_is_ignored(): void
    {
        Livewire::test(HomeDashboard::class)
            ->call('selectRange', 'yearly')
            ->assertSet('range', 'monthly');
    }

    // === Fixtures ===

    private function processed(HomeDashboard $page): float
    {
        return (float) $page->metrics()['kpi']['processed_revenue']['value'];
    }

    private function revenue(float $amount, int $daysAgo): void
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => $amount,
            'installment_amount' => $amount,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_email' => 'dana@example.com',
        ]);
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        $ledger = new PaymentLedger;
        $ledger->forceFill([
            'shop_id' => $this->shop->getKey(),
            'plan_id' => $plan->getKey(),
            'idempotency_key' => (string) Str::ulid(),
            'charge_context' => 'recurring',
            'amount' => $amount,
            'currency' => 'ILS',
            'status' => PaymentLedger::STATUS_SUCCEEDED,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ])->save();
    }
}
