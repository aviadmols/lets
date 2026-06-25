<?php

namespace Tests\Feature\Observability;

use App\Filament\Pages\ObservabilityDashboard;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ObservabilityDashboard page — renders for a bound merchant (their own numbers)
 * and for a platform admin in platform mode (the cross-shop aggregate). Proves a
 * merchant can NEVER see another shop's numbers: the page chooses the tenant-scoped
 * metrics for a bound user and only ever the platform aggregate for an unbound
 * platform admin.
 */
final class ObservabilityDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_merchant_sees_their_own_metrics_only(): void
    {
        $shopA = $this->makeShop('dash-a.myshopify.com');
        $shopB = $this->makeShop('dash-b.myshopify.com');

        // A: 1 succeeded 100. B: 1 succeeded 999 (must never appear for A).
        Tenant::run($shopA, fn () => $this->ledger($shopA, PaymentLedger::STATUS_SUCCEEDED, 100));
        Tenant::run($shopB, fn () => $this->ledger($shopB, PaymentLedger::STATUS_SUCCEEDED, 999));

        // Bind A like the panel middleware does + act as A's merchant.
        Tenant::set($shopA);
        $this->actingAs(User::factory()->forShop($shopA)->create());

        $component = Livewire::test(ObservabilityDashboard::class)->assertOk();
        $data = $component->instance()->data();

        $this->assertFalse($data['is_platform']);
        $this->assertSame(1, $data['counts_24h']['succeeded']);
        $this->assertSame(100.0, $data['counts_24h']['total_charged']); // B's 999 excluded

        // The "This shop" scope banner shows, not the platform aggregate label.
        $component->assertSee(__('observability.scope.shop'))
            ->assertDontSee(__('observability.scope.platform'));
    }

    public function test_platform_admin_in_platform_mode_sees_cross_shop_aggregate(): void
    {
        $shopA = $this->makeShop('agg-dash-a.myshopify.com');
        $shopB = $this->makeShop('agg-dash-b.myshopify.com');

        Tenant::run($shopA, fn () => $this->ledger($shopA, PaymentLedger::STATUS_SUCCEEDED, 100));
        Tenant::run($shopB, fn () => $this->ledger($shopB, PaymentLedger::STATUS_SUCCEEDED, 200));

        // Platform admin, NO shop entered → platform mode.
        Tenant::clear();
        $this->actingAs(User::factory()->platformAdmin()->create());

        $component = Livewire::test(ObservabilityDashboard::class)->assertOk();
        $data = $component->instance()->data();

        $this->assertTrue($data['is_platform']);
        $this->assertSame(2, $data['counts_24h']['succeeded']);          // both shops
        $this->assertSame(300.0, $data['counts_24h']['total_charged']);  // 100 + 200

        $component->assertSee(__('observability.scope.platform'));
    }

    public function test_entered_platform_admin_is_scoped_to_the_entered_shop(): void
    {
        $shopA = $this->makeShop('enter-a.myshopify.com');
        $shopB = $this->makeShop('enter-b.myshopify.com');

        Tenant::run($shopA, fn () => $this->ledger($shopA, PaymentLedger::STATUS_SUCCEEDED, 100));
        Tenant::run($shopB, fn () => $this->ledger($shopB, PaymentLedger::STATUS_SUCCEEDED, 999));

        // A platform admin who has ENTERED shop A is tenant-bound → per-shop scope.
        Tenant::set($shopA);
        $this->actingAs(User::factory()->platformAdmin()->create());

        $data = Livewire::test(ObservabilityDashboard::class)->instance()->data();

        // Bound → NOT the platform aggregate; only shop A's numbers.
        $this->assertFalse($data['is_platform']);
        $this->assertSame(1, $data['counts_24h']['succeeded']);
        $this->assertSame(100.0, $data['counts_24h']['total_charged']);
    }

    public function test_shopless_non_platform_user_cannot_access(): void
    {
        $this->actingAs(User::factory()->create(['shop_id' => null]));
        Tenant::clear();

        $this->assertFalse(ObservabilityDashboard::canAccess());
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Store '.$domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }

    private function ledger(Shop $shop, string $status, float $amount): PaymentLedger
    {
        $row = PaymentLedger::create([
            'charge_context' => PaymentLedger::CONTEXT_RECURRING,
            'idempotency_key' => 'dash-'.Str::random(12),
            'amount' => $amount,
            'currency' => 'ILS',
        ]);
        $row->forceFill(['status' => $status])->save();

        return $row;
    }
}
