<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\AnalyticsMetrics;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Analytics aggregates: KPIs count BOTH rails, one shopper on both rails is
 * ONE subscriber, MRR normalises each cadence to a month, and the trend line
 * ends at today's real active count.
 */
final class AnalyticsMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'analytics.myshopify.com',
            'name' => 'Analytics',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_kpis_count_both_rails_and_dedupe_the_subscriber(): void
    {
        // The SAME shopper (id 55) on both rails + a second contract-only shopper.
        $this->plan(customerId: '55', amount: 100, frequency: 'monthly');
        $this->contract(customerId: '55', amount: 73);
        $this->contract(customerId: '77', amount: 30, gidSuffix: 'b');

        $m = AnalyticsMetrics::forRange(30);

        $this->assertSame(2, $m['active_subscribers'], 'One human on two rails is one subscriber.');
        $this->assertSame(3, $m['active_subscriptions']);
        $this->assertSame(3, $m['products_quantity']);
        // 100 (monthly plan) + 73 + 30 (monthly contracts) — all factor 1.
        $this->assertSame(203.0, $m['mrr']);
    }

    public function test_mrr_normalises_cadence_to_a_month(): void
    {
        // A yearly 120 = 10/month; a weekly 7 ≈ 30.44/7 * 7 = 30.44/month.
        $this->plan(customerId: '1', amount: 120, frequency: 'yearly');
        $this->plan(customerId: '2', amount: 7, frequency: 'weekly');

        $m = AnalyticsMetrics::forRange(30);

        $this->assertEqualsWithDelta(10.0 + 30.44, $m['mrr'], 0.1);
    }

    public function test_the_trend_line_ends_at_the_current_active_count(): void
    {
        $this->plan(customerId: '1', amount: 50, frequency: 'monthly');
        $this->contract(customerId: '2', amount: 60);

        $m = AnalyticsMetrics::forRange(7);

        $this->assertCount(7, $m['trend']);
        $this->assertSame(2, end($m['trend'])['active'], 'The line must end at the KPI number.');
    }

    public function test_cancellations_come_from_the_timeline(): void
    {
        $plan = $this->plan(customerId: '1', amount: 50, frequency: 'monthly');

        ActivityEvent::query()->create([
            'shop_id' => $this->shop->getKey(),
            'plan_id' => $plan->getKey(),
            'actor' => 'system',
            'kind' => 'status_changed',
            'details' => ['from' => 'active', 'to' => 'cancelled'],
        ]);

        $m = AnalyticsMetrics::forRange(7);

        $this->assertSame(1, end($m['trend'])['cancelled']);
    }

    // === Fixtures ===

    private function plan(string $customerId, float $amount, string $frequency): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'shopify_customer_id' => $customerId,
            'total_amount' => $amount,
            'total_charged' => 0,
            'installment_amount' => $amount,
            'billing_frequency' => $frequency,
            'interval_count' => 1,
            'currency' => 'ILS',
            'public_id' => 'PLAN-'.uniqid(),
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan;
    }

    private function contract(string $customerId, float $amount, string $gidSuffix = 'a'): SubscriptionContract
    {
        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => $this->shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.$customerId.$gidSuffix,
            'shopify_customer_gid' => 'gid://shopify/Customer/'.$customerId,
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'amount' => $amount,
            'currency' => 'ILS',
            'synced_at' => now(),
        ])->save();

        return $contract;
    }
}
