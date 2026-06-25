<?php

namespace Tests\Feature\Observability;

use App\Domain\Observability\ObservabilityMetrics;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ObservabilityMetrics — charge-health + plan + queue + heartbeat aggregation.
 * Proves: success-rate/counts maths; tenant isolation (shop A's rows never count
 * for shop B); forPlatform() aggregates across shops via the audited bypass; and
 * the infra reads (queue depth, heartbeat) degrade gracefully when Redis/cache is
 * absent or empty.
 */
final class ObservabilityMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_charge_success_rate_and_counts_are_tenant_scoped(): void
    {
        $shopA = $this->makeShop('a-obs.myshopify.com');
        $shopB = $this->makeShop('b-obs.myshopify.com');

        // Shop A: 3 succeeded, 1 failed → 75% success.
        Tenant::run($shopA, function (): void {
            $this->ledger(PaymentLedger::STATUS_SUCCEEDED, 100);
            $this->ledger(PaymentLedger::STATUS_SUCCEEDED, 100);
            $this->ledger(PaymentLedger::STATUS_SUCCEEDED, 100);
            $this->ledger(PaymentLedger::STATUS_FAILED, 100);
        });

        // Shop B: 1 succeeded, 3 failed → 25% (must NOT bleed into A's numbers).
        Tenant::run($shopB, function (): void {
            $this->ledger(PaymentLedger::STATUS_SUCCEEDED, 50);
            $this->ledger(PaymentLedger::STATUS_FAILED, 50);
            $this->ledger(PaymentLedger::STATUS_FAILED, 50);
            $this->ledger(PaymentLedger::STATUS_FAILED, 50);
        });

        $rateA = Tenant::run($shopA, fn (): ?float => ObservabilityMetrics::forCurrentShop()
            ->chargeSuccessRate(ObservabilityMetrics::WINDOW_24H));
        $countsA = Tenant::run($shopA, fn (): array => ObservabilityMetrics::forCurrentShop()
            ->counts(ObservabilityMetrics::WINDOW_24H));

        $this->assertSame(75.0, $rateA);
        $this->assertSame(3, $countsA['succeeded']);
        $this->assertSame(1, $countsA['failed']);
        $this->assertSame(300.0, $countsA['total_charged']); // A's 3×100 only — B excluded

        $rateB = Tenant::run($shopB, fn (): ?float => ObservabilityMetrics::forCurrentShop()
            ->chargeSuccessRate(ObservabilityMetrics::WINDOW_24H));
        $this->assertSame(25.0, $rateB);
    }

    public function test_success_rate_is_null_when_nothing_attempted(): void
    {
        $shop = $this->makeShop('empty-obs.myshopify.com');

        $rate = Tenant::run($shop, fn (): ?float => ObservabilityMetrics::forCurrentShop()
            ->chargeSuccessRate(ObservabilityMetrics::WINDOW_24H));

        // No succeeded + no failed → null (the page shows an em-dash, never 0%/100%).
        $this->assertNull($rate);
    }

    public function test_for_platform_aggregates_across_all_shops(): void
    {
        $shopA = $this->makeShop('agg-a.myshopify.com');
        $shopB = $this->makeShop('agg-b.myshopify.com');

        Tenant::run($shopA, fn () => $this->ledger(PaymentLedger::STATUS_SUCCEEDED, 100));
        Tenant::run($shopA, fn () => $this->ledger(PaymentLedger::STATUS_FAILED, 100));
        Tenant::run($shopB, fn () => $this->ledger(PaymentLedger::STATUS_SUCCEEDED, 200));
        Tenant::run($shopB, fn () => $this->ledger(PaymentLedger::STATUS_FAILED, 200));

        // Platform mode: NO tenant bound — the aggregate must still see all 4 rows.
        Tenant::clear();
        $counts = ObservabilityMetrics::forPlatform()->counts(ObservabilityMetrics::WINDOW_24H);
        $rate = ObservabilityMetrics::forPlatform()->chargeSuccessRate(ObservabilityMetrics::WINDOW_24H);

        $this->assertSame(2, $counts['succeeded']);
        $this->assertSame(2, $counts['failed']);
        $this->assertSame(300.0, $counts['total_charged']); // 100 + 200 across shops
        $this->assertSame(50.0, $rate);
    }

    public function test_recent_failures_are_tenant_scoped_and_carry_shop_id_only_for_platform(): void
    {
        $shopA = $this->makeShop('fail-a.myshopify.com');
        $shopB = $this->makeShop('fail-b.myshopify.com');

        Tenant::run($shopA, fn () => $this->ledger(PaymentLedger::STATUS_FAILED, 100, failureMessage: 'card declined'));
        Tenant::run($shopB, fn () => $this->ledger(PaymentLedger::STATUS_FAILED, 100, failureMessage: 'B only'));

        // Tenant scope: shop A sees only its own failure, with shop_id nulled out.
        $aFailures = Tenant::run($shopA, fn (): array => ObservabilityMetrics::forCurrentShop()->recentFailures());
        $this->assertCount(1, $aFailures);
        $this->assertSame('card declined', $aFailures[0]['failure_message']);
        $this->assertNull($aFailures[0]['shop_id']);

        // Platform scope: both failures, each tagged with its owning shop_id.
        Tenant::clear();
        $allFailures = ObservabilityMetrics::forPlatform()->recentFailures();
        $this->assertCount(2, $allFailures);
        $shopIds = array_filter(array_column($allFailures, 'shop_id'));
        $this->assertContains($shopA->id, $shopIds);
        $this->assertContains($shopB->id, $shopIds);
    }

    public function test_active_plans_breakdown_is_tenant_scoped(): void
    {
        $shopA = $this->makeShop('plans-a.myshopify.com');
        $shopB = $this->makeShop('plans-b.myshopify.com');

        Tenant::run($shopA, function (): void {
            $this->plan(PlanStatus::ACTIVE);
            $this->plan(PlanStatus::ACTIVE);
            $this->plan(PlanStatus::PAUSED);
        });
        Tenant::run($shopB, fn () => $this->plan(PlanStatus::ACTIVE));

        $breakdown = Tenant::run($shopA, fn (): array => ObservabilityMetrics::forCurrentShop()->activePlans());

        $this->assertSame(2, $breakdown['active']);   // B's active plan excluded
        $this->assertSame(1, $breakdown['paused']);
        $this->assertSame(0, $breakdown['failed']);
    }

    public function test_queue_depth_degrades_gracefully_without_redis(): void
    {
        // The test suite uses the sync/array queue — Queue::size() may throw or be
        // unsupported. Either way the method must return a value per queue, never 500.
        $depths = ObservabilityMetrics::forPlatform()->queueDepth();

        $this->assertSame(ObservabilityMetrics::QUEUES, array_keys($depths));
        foreach ($depths as $depth) {
            $this->assertTrue(is_int($depth) || $depth === ObservabilityMetrics::UNAVAILABLE);
        }
    }

    public function test_scheduler_heartbeat_reads_the_cache_key(): void
    {
        // Fresh / never run → not healthy, no timestamp.
        Cache::forget(ObservabilityMetrics::HEARTBEAT_KEY);
        $cold = ObservabilityMetrics::forPlatform()->schedulerHeartbeat();
        $this->assertFalse($cold['healthy']);
        $this->assertNull($cold['last_run']);

        // A recent run → healthy.
        Cache::forever(ObservabilityMetrics::HEARTBEAT_KEY, now()->toIso8601String());
        $warm = ObservabilityMetrics::forPlatform()->schedulerHeartbeat();
        $this->assertTrue($warm['healthy']);
        $this->assertNotNull($warm['last_run']);

        // A stale run (well past the healthy window) → unhealthy but still reported.
        Cache::forever(ObservabilityMetrics::HEARTBEAT_KEY, now()->subHours(3)->toIso8601String());
        $stale = ObservabilityMetrics::forPlatform()->schedulerHeartbeat();
        $this->assertFalse($stale['healthy']);
        $this->assertNotNull($stale['last_run']);
        $this->assertGreaterThan(ObservabilityMetrics::HEARTBEAT_HEALTHY_MINUTES, $stale['age_minutes']);
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Store '.$domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    /** Seed a ledger row in the bound tenant. status is guarded → set via forceFill. */
    private function ledger(string $status, float $amount, ?string $failureMessage = null): PaymentLedger
    {
        $row = PaymentLedger::create([
            'charge_context' => PaymentLedger::CONTEXT_RECURRING,
            'idempotency_key' => 'obs-'.Str::random(12),
            'amount' => $amount,
            'currency' => 'ILS',
            'failure_message' => $failureMessage,
        ]);
        $row->forceFill(['status' => $status])->save();

        return $row;
    }

    private function plan(PlanStatus $status): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'total_amount' => 600,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'customer_name' => 'Dana',
            'customer_email' => 'dana@example.com',
        ]);
        $plan->forceFill(['status' => $status->value])->save();

        return $plan;
    }
}
