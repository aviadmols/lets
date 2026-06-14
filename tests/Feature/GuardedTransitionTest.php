<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Exceptions\IllegalTransitionException;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The state machine is guarded: only canonical transitions are legal, illegal
 * ones throw, and every accepted move writes a Timeline (activity_events) row.
 */
final class GuardedTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_legal_transition_succeeds_and_records_timeline(): void
    {
        $plan = $this->makePlan(PlanStatus::ACTIVE);

        $plan->transitionTo(PlanStatus::PAUSED);

        $this->assertSame(PlanStatus::PAUSED, $plan->refresh()->status);

        $event = ActivityEvent::where('plan_id', $plan->id)
            ->where('kind', 'status_changed')
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('active', $event->details['from']);
        $this->assertSame('paused', $event->details['to']);
    }

    public function test_illegal_transition_throws_and_does_not_change_state(): void
    {
        $plan = $this->makePlan(PlanStatus::ACTIVE);

        // active → completed is legal; active → draft is NOT.
        $this->expectException(IllegalTransitionException::class);

        try {
            $plan->transitionTo(PlanStatus::DRAFT);
        } finally {
            $this->assertSame(PlanStatus::ACTIVE, $plan->refresh()->status);
        }
    }

    private function makePlan(PlanStatus $status): InstallmentPlan
    {
        $shop = Shop::create([
            'shopify_domain' => 'sm.myshopify.com',
            'status' => Shop::STATUS_INSTALLED,
        ]);

        // Bind the tenant for the rest of the test (mirrors a real request/job
        // where one shop is bound throughout). tearDown() clears it. Without a
        // bound tenant, the scoped ActivityEvent assertion filters on a null
        // shop_id and never sees the (correctly shop-stamped) event.
        Tenant::set($shop);

        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'total_amount' => 100,
            'installment_amount' => 50,
            'currency' => 'ILS',
        ]);
        // status is guarded (FIX #5) — set the initial state via forceFill.
        $plan->forceFill(['status' => $status->value])->save();

        return $plan;
    }
}
