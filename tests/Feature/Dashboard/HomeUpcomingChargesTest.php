<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\HomeDashboard;
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
 * The Home "Upcoming orders" panel: the next scheduled charges (subscriptions + installments),
 * soonest first, tenant-scoped. Proves BelongsToShop isolation (never another shop's plans),
 * ordering, and that past / terminal plans are excluded.
 */
final class HomeUpcomingChargesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['shopify_domain' => 'home-up.myshopify.com', 'name' => 'HU', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'hu@test.test', 'password' => bcrypt('password')]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_upcoming_is_tenant_scoped_and_soonest_first(): void
    {
        // This shop: two future charges (recurring + installments), out of date order.
        $this->makePlan($this->shop, 'Bob', PlanKind::INSTALLMENTS, now()->addDays(5));
        $this->makePlan($this->shop, 'Alice', PlanKind::RECURRING, now()->addDays(2));

        // A rival shop with an even-sooner charge that must NEVER appear here.
        $other = Shop::create(['shopify_domain' => 'rival.myshopify.com', 'name' => 'R', 'status' => Shop::STATUS_ACTIVE]);
        $this->makePlan($other, 'Rival', PlanKind::RECURRING, now()->addDay());

        $rows = Livewire::test(HomeDashboard::class)->instance()->upcomingCharges();

        $this->assertCount(2, $rows);
        $this->assertSame(['Alice', 'Bob'], array_column($rows, 'customer')); // soonest first, this shop only
        $this->assertArrayHasKey('amount', $rows[0]);
        $this->assertArrayHasKey('date', $rows[0]);
        $this->assertArrayHasKey('url', $rows[0]);
    }

    public function test_past_and_terminal_charges_are_excluded(): void
    {
        $this->makePlan($this->shop, 'PastDue', PlanKind::RECURRING, now()->subDay());               // already past
        $this->makePlan($this->shop, 'Cancelled', PlanKind::RECURRING, now()->addDays(3), PlanStatus::CANCELLED);

        $rows = Livewire::test(HomeDashboard::class)->instance()->upcomingCharges();

        $this->assertSame([], $rows);
    }

    private function makePlan(Shop $shop, string $customer, PlanKind $kind, \DateTimeInterface $next, PlanStatus $status = PlanStatus::ACTIVE): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($customer, $kind, $next, $status): InstallmentPlan {
            $plan = InstallmentPlan::create([
                'plan_kind' => $kind->value,
                'installment_amount' => 49.90,
                'total_amount' => 149.90,
                'total_charged' => 50.00,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'customer_name' => $customer,
                'next_charge_at' => $next,
            ]);
            $plan->forceFill(['status' => $status->value])->save();

            return $plan;
        });
    }
}
