<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\PaymentMetrics;
use App\Filament\Pages\Analytics;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The money half of Analytics, read from the ledger.
 *
 * Two things are worth pinning down. The success RATE is measured against
 * settled money only — a charge that has not come back yet is not a failure, and
 * counting it as one would make every busy hour look like an outage. And the
 * upcoming table's dates are LINKS: a number nobody can open is a number nobody
 * can act on, so each day carries the subscriptions-list URL with the date
 * filter already set to that single day.
 */
final class PaymentMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'payment-metrics.example.com',
            'name' => 'Metrics',
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

    public function test_the_snapshot_separates_collected_from_lost_and_still_retrying(): void
    {
        $this->ledger(LedgerStatus::SUCCEEDED, 300);
        $this->ledger(LedgerStatus::SUCCEEDED, 200);
        $this->ledger(LedgerStatus::FAILED, 100);
        $this->ledger(LedgerStatus::RETRY_SCHEDULED, 50);
        $this->ledger(LedgerStatus::PENDING, 25);

        $snapshot = PaymentMetrics::snapshot(30);

        $this->assertSame(500.0, $snapshot['realized']);
        $this->assertSame(100.0, $snapshot['lost']);
        $this->assertSame(50.0, $snapshot['retrying']);
        $this->assertSame(675.0, $snapshot['attempted'], 'every opened row is an attempt');

        // 500 collected of 600 SETTLED. The pending 25 and retrying 50 are not
        // failures and must not drag the rate down.
        $this->assertSame(83.33, $snapshot['success_rate']);
    }

    public function test_a_shop_with_nothing_settled_reports_zero_rather_than_dividing_by_zero(): void
    {
        $this->ledger(LedgerStatus::PENDING, 90);

        $snapshot = PaymentMetrics::snapshot(30);

        $this->assertSame(0.0, $snapshot['success_rate']);
        $this->assertSame(90.0, $snapshot['attempted']);
    }

    public function test_charges_outside_the_window_are_not_counted(): void
    {
        $this->ledger(LedgerStatus::SUCCEEDED, 400, now()->subDays(90));

        $this->assertSame(0.0, PaymentMetrics::snapshot(30)['realized']);
        $this->assertSame(400.0, PaymentMetrics::snapshot(120)['realized']);
    }

    public function test_the_monthly_history_always_returns_a_full_year_of_slots(): void
    {
        $this->ledger(LedgerStatus::SUCCEEDED, 100);

        $months = PaymentMetrics::monthly();

        $this->assertCount(PaymentMetrics::MONTHS, $months, 'an empty month is still a month');
        $this->assertSame(now()->format('Y-m'), end($months)['month'], 'newest last');
        $this->assertSame(100.0, end($months)['realized']);
    }

    public function test_each_upcoming_day_links_into_the_list_filtered_to_that_day(): void
    {
        $due = now()->addDays(3)->startOfDay();
        $this->plan($due);
        $this->plan($due);
        $this->plan(now()->addDays(9)->startOfDay());

        $rows = Livewire::test(Analytics::class)->instance()->upcoming();

        $first = collect($rows)->firstWhere('date', $due->toDateString());

        $this->assertNotNull($first, 'the day with two subscriptions is listed');
        $this->assertSame(2, $first['count']);

        // Both ends of the range are that one date — which is what makes the
        // link a drill-down rather than "everything from here on".
        $this->assertStringContainsString(urlencode($due->toDateString()), $first['url']);
        $this->assertStringContainsString('next_charge_at', $first['url']);
    }

    /** A yearly member's charge is a year out; the table must not stop at a window. */
    public function test_the_upcoming_table_reaches_all_future_charges(): void
    {
        $farOut = now()->addMonths(11)->startOfDay();
        $this->plan($farOut);

        $rows = Livewire::test(Analytics::class)->instance()->upcoming();

        $this->assertNotNull(
            collect($rows)->firstWhere('date', $farOut->toDateString()),
            'a charge eleven months out is listed',
        );
    }

    // === Helpers ===

    private function ledger(LedgerStatus $status, float $amount, ?\DateTimeInterface $at = null): void
    {
        $row = new PaymentLedger;
        $row->forceFill([
            'shop_id' => $this->shop->getKey(),
            'charge_context' => 'recurring',
            'idempotency_key' => 'key-'.uniqid('', true),
            'amount' => $amount,
            'currency' => 'ILS',
            'status' => $status->value,
            'created_at' => $at ?? now(),
            'updated_at' => $at ?? now(),
        ])->save();
    }

    private function plan(\DateTimeInterface $due): void
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 75,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => $due,
        ])->save();
    }
}
