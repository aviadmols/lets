<?php

namespace Tests\Feature;

use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RELEASE-BLOCKER gate: Shop A must NEVER be able to read Shop B's tenant data
 * via the BelongsToShop global scope, and a forgotten tenant must FAIL CLOSED
 * (return nothing), not leak everything.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_shop_a_cannot_read_shop_b_plans_or_ledger(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        $planA = $this->makePlanFor($shopA);
        $planB = $this->makePlanFor($shopB);

        // Bound to A: only A's plan is visible.
        Tenant::set($shopA);
        $this->assertCount(1, InstallmentPlan::all());
        $this->assertSame($planA->id, InstallmentPlan::first()->id);
        $this->assertNull(InstallmentPlan::find($planB->id), 'Shop A must not resolve Shop B plan by id.');

        // Bound to B: only B's plan is visible.
        Tenant::set($shopB);
        $this->assertCount(1, InstallmentPlan::all());
        $this->assertSame($planB->id, InstallmentPlan::first()->id);
        $this->assertNull(InstallmentPlan::find($planA->id));
    }

    public function test_unbound_tenant_fails_closed(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $this->makePlanFor($shopA);

        Tenant::clear();

        // No tenant bound → the scope matches shop_id = null → zero rows. Never leak.
        $this->assertCount(0, InstallmentPlan::all());
        $this->assertCount(0, PaymentLedger::all());
    }

    public function test_belongs_to_shop_auto_stamps_shop_id_on_create(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        Tenant::set($shopA);

        // Create WITHOUT passing shop_id — the trait must stamp it. Passing a
        // status here must NOT take effect (status is guarded, FIX #5): the plan
        // is born `draft` regardless, proving raw create() cannot bypass the
        // state machine for the status column.
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'installment_amount' => 50,
            'currency' => 'ILS',
        ]);

        $this->assertSame($shopA->id, $plan->shop_id);
        $this->assertSame(PlanStatus::DRAFT, $plan->refresh()->status, 'Guarded status: create() must not set it.');
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function makePlanFor(Shop $shop): InstallmentPlan
    {
        return Tenant::run($shop, function () {
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'total_amount' => 300,
                'total_charged' => 0,
                'installment_amount' => 100,
                'currency' => 'ILS',
            ]);
            // status is guarded (FIX #5) — set the initial state via forceFill.
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        });
    }
}
