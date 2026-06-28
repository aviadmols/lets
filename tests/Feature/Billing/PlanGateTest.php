<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingPlan;
use App\Domain\Billing\PlanGate;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SaaS billing-plan foundation. Owner's locked decision: ONE plan (FREE),
 * everything unlimited / all features on, no charging. These tests prove:
 *   - FREE allows every feature + is unlimited on every counter (non-blocking);
 *   - a shop with no/blank/unknown plan defaults to FREE (fail-safe, no paid grant);
 *   - the gate is per-shop (tenant-correct);
 *   - the ENFORCEMENT SEAM works even though no paid tier is active — a capped
 *     limit blocks past it (proves paid tiers will just be numbers, not new wiring).
 */
final class PlanGateTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const DOMAIN_A = 'a.myshopify.com';
    private const DOMAIN_B = 'b.myshopify.com';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === FREE: non-blocking for every current shop ===

    public function test_free_allows_every_boolean_feature(): void
    {
        $gate = PlanGate::forPlan(BillingPlan::FREE);

        foreach (BillingPlan::FEATURE_KEYS as $feature) {
            $this->assertTrue($gate->allows($feature), "FREE must allow {$feature}.");
        }
    }

    public function test_free_is_unlimited_on_every_counter(): void
    {
        $gate = PlanGate::forPlan(BillingPlan::FREE);

        foreach (BillingPlan::COUNTER_KEYS as $key) {
            $this->assertNull($gate->limit($key), "FREE must be unlimited on {$key}.");
            // Unlimited → always room for one more, at any count (incl. huge ones).
            $this->assertTrue($gate->within($key, 0));
            $this->assertTrue($gate->within($key, 50_000));
            $this->assertNull($gate->remaining($key, 50_000), 'Unlimited → null remaining.');
        }
    }

    public function test_unknown_feature_key_fails_open(): void
    {
        // Fail OPEN by design: this gate protects vendor pricing, not customer data.
        // An unknown key must never wrongly block a merchant or 500.
        $this->assertTrue(PlanGate::forPlan(BillingPlan::FREE)->allows('not_a_real_feature'));
        $this->assertNull(PlanGate::forPlan(BillingPlan::FREE)->limit('not_a_real_counter'));
        $this->assertTrue(PlanGate::forPlan(BillingPlan::FREE)->within('not_a_real_counter', 999));
    }

    // === Default-to-FREE: fail-safe plan resolution ===

    public function test_shop_with_no_plan_defaults_to_free(): void
    {
        $this->assertSame(BillingPlan::FREE, BillingPlan::fromCode(null));
        $this->assertSame(BillingPlan::FREE, BillingPlan::fromCode(''));
        $this->assertSame(BillingPlan::FREE, BillingPlan::fromCode('   '));
        // An unknown stored value must NOT silently grant a paid tier.
        $this->assertSame(BillingPlan::FREE, BillingPlan::fromCode('enterprise-unknown'));
    }

    public function test_shop_accessor_resolves_to_free_when_plan_unset(): void
    {
        $shop = Shop::create([
            'shopify_domain' => self::DOMAIN_A,
            'name' => 'A',
            'status' => Shop::STATUS_INSTALLED,
            'plan' => null,
        ]);

        $this->assertSame(BillingPlan::FREE, $shop->billingPlan());
        $this->assertFalse($shop->isOnPaidPlan(), 'FREE is not a paid tier.');
        $this->assertSame(0.0, $shop->billingPlan()->monthlyPriceUsd());

        // The gate built from a default shop is fully permissive.
        $gate = PlanGate::for($shop);
        $this->assertTrue($gate->allows(BillingPlan::FEATURE_POST_PURCHASE));
        $this->assertTrue($gate->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, 1_000));
    }

    public function test_explicit_free_column_resolves_to_free(): void
    {
        $shop = Shop::create([
            'shopify_domain' => self::DOMAIN_A,
            'name' => 'A',
            'status' => Shop::STATUS_INSTALLED,
            'plan' => 'free',
        ]);

        $this->assertSame(BillingPlan::FREE, $shop->billingPlan());
    }

    // === Per-shop: the gate reads each shop's OWN plan ===

    public function test_plan_gate_is_per_shop(): void
    {
        $shopA = Shop::create([
            'shopify_domain' => self::DOMAIN_A, 'name' => 'A',
            'status' => Shop::STATUS_INSTALLED, 'plan' => 'free',
        ]);
        // A future paid value on B must not bleed into A's gate, and an unknown
        // value on B still resolves to FREE (fail-safe) — proving resolution is
        // strictly per-row, read from each shop's own column.
        $shopB = Shop::create([
            'shopify_domain' => self::DOMAIN_B, 'name' => 'B',
            'status' => Shop::STATUS_INSTALLED, 'plan' => 'some-future-tier',
        ]);

        $this->assertSame(BillingPlan::FREE, $shopA->billingPlan());
        $this->assertSame(BillingPlan::FREE, $shopB->billingPlan());
        $this->assertSame($shopA->billingPlan(), PlanGate::for($shopA)->plan());
        $this->assertSame($shopB->billingPlan(), PlanGate::for($shopB)->plan());
    }

    // === The ENFORCEMENT SEAM works even with no active paid tier ===

    public function test_capped_counter_blocks_past_the_limit(): void
    {
        // Drive a REAL PlanGate against a hypothetical capped tier (cap = 5). This
        // proves the enforcement seam works BEFORE any paid tier ships: adding a
        // paid tier is just numbers in BillingPlan::limits(), not new gate wiring.
        $cap = 5;
        $gate = PlanGate::withLimits(BillingPlan::FREE, [
            BillingPlan::LIMIT_MAX_UPSELL_FLOWS => $cap,
        ]);

        $this->assertSame($cap, $gate->limit(BillingPlan::LIMIT_MAX_UPSELL_FLOWS));

        // Room while strictly below the cap.
        $this->assertTrue($gate->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, 0));
        $this->assertTrue($gate->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $cap - 1));
        $this->assertSame(1, $gate->remaining(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $cap - 1));

        // Blocked at and above the cap; remaining never negative.
        $this->assertFalse($gate->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $cap));
        $this->assertFalse($gate->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $cap + 3));
        $this->assertSame(0, $gate->remaining(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $cap));
        $this->assertSame(0, $gate->remaining(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $cap + 3));
    }

    public function test_capped_boolean_feature_blocks_when_off(): void
    {
        // The boolean side of the seam through a REAL gate: FREE's "on" path AND the
        // "off" path a paid tier takes when it disables a feature (allows() → false,
        // so the caller shows an upgrade CTA instead of running the capability).
        $this->assertTrue(PlanGate::forPlan(BillingPlan::FREE)->allows(BillingPlan::FEATURE_POST_PURCHASE));

        $capped = PlanGate::withLimits(BillingPlan::FREE, [
            BillingPlan::FEATURE_POST_PURCHASE => false,
        ]);
        $this->assertFalse($capped->allows(BillingPlan::FEATURE_POST_PURCHASE));
    }

    // === The wired example gate (PostPurchaseOffers::createFlow) passes for FREE ===

    public function test_example_upsell_flow_gate_passes_for_free(): void
    {
        // Mirror the exact check createFlow() runs: a FREE shop with existing flows
        // is NEVER blocked from creating another (max_upsell_flows is unlimited).
        $shop = Shop::create([
            'shopify_domain' => self::DOMAIN_A, 'name' => 'A',
            'status' => Shop::STATUS_INSTALLED, 'plan' => 'free',
        ]);

        Tenant::run($shop, function () use ($shop): void {
            // Seed a couple of real flows so the count is non-trivial.
            $this->makeFlow();
            $this->makeFlow();

            $existing = (int) UpsellFlow::query()->count();
            $this->assertSame(2, $existing);

            // The same expression as PostPurchaseOffers::createFlow — true for FREE.
            $this->assertTrue(
                PlanGate::for($shop)->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $existing),
                'FREE must always allow another upsell flow.',
            );
        });
    }

    private function makeFlow(): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'flow', 'priority' => 1]);
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        return $flow;
    }
}
