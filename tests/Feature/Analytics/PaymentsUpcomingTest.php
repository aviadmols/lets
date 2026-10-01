<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Payments\UpcomingPaymentsQuery;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\Screens\PaymentsUpcoming;
use App\Livewire\Analytics\UpcomingPaymentsTable;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsPayments;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Payments › Upcoming payments: the window, risk levels (card, last charge,
 * owing), search / sort / pagination in SQL across both rails, the per-day
 * drill-down link into Subscriptions — and tenant isolation.
 */
final class PaymentsUpcomingTest extends TestCase
{
    use BuildsPayments;
    use BuildsSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    private Shop $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('up-a');
        $this->other = $this->makeShop('up-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function upcomingPlan(Shop $shop, string $customer, string $due, float $amount = 50, string $status = 'active', ?array $card = [12, 2030]): \App\Models\InstallmentPlan
    {
        $plan = $this->plan($shop, $customer, $amount, 'monthly', 1, $status, null, [
            'customer_name' => 'Customer '.$customer,
            'customer_email' => 'c'.$customer.'@example.test',
            'public_id' => 'SUB-'.$customer,
        ]);
        DB::table('installment_plans')->where('id', $plan->id)->update(['next_charge_at' => CarbonImmutable::parse($due)]);
        if ($card !== null) {
            $this->paymentMethod($plan, $card[0], $card[1]);
        }

        return $plan;
    }

    private function query(int $days = 7): UpcomingPaymentsQuery
    {
        return new UpcomingPaymentsQuery(Filters::none(), $days);
    }

    public function test_window_risk_and_summary(): void
    {
        $this->upcomingPlan($this->shop, '1', '2026-09-30 09:00', 49);                     // low
        $this->upcomingPlan($this->shop, '2', '2026-10-01 09:00', 89, 'active', [10, 2026]); // expiring that month → medium
        $this->upcomingPlan($this->shop, '3', '2026-10-02 09:00', 59, 'active', null);      // no card → high
        $failed = $this->upcomingPlan($this->shop, '4', '2026-10-03 09:00', 39);
        $this->ledger($failed, 39, 'failed', CarbonImmutable::parse('2026-09-03'), 'k4');  // last charge failed → high
        $this->upcomingPlan($this->shop, '5', '2026-10-20 09:00', 99);                     // outside 7 days
        $this->upcomingPlan($this->shop, '6', '2026-10-01 09:00', 10, 'cancelled');        // not billed
        $this->upcomingPlan($this->other, '7', '2026-09-30 09:00', 500);                    // another shop

        $s = Tenant::run($this->shop, fn () => $this->query()->summary());

        $this->assertSame(4, $s['count']);
        $this->assertEqualsWithDelta(49 + 89 + 59 + 39, $s['amount'], 0.01);
        $this->assertSame(2, $s['high']);
        $this->assertSame(1, $s['medium']);

        $rows = Tenant::run($this->shop, fn () => $this->query()->page('', UpcomingPaymentsQuery::SORT_RISK)['rows']);
        $this->assertSame(['SUB-3', 'SUB-4', 'SUB-2', 'SUB-1'], array_map(static fn ($r) => $r->ref, $rows));
        $this->assertSame(UpcomingPaymentsQuery::METHOD_MISSING, $rows[0]->method);
        $this->assertSame(UpcomingPaymentsQuery::METHOD_EXPIRING, $rows[2]->method);
        $this->assertSame('failed', $rows[1]->last_status);

        $this->assertSame(5, Tenant::run($this->shop, fn () => $this->query(30)->summary())['count']);
    }

    public function test_retries_left_and_the_shopify_rail(): void
    {
        $plan = $this->upcomingPlan($this->shop, '1', '2026-09-30 09:00');
        DB::table('installment_payments')->insert([
            'shop_id' => $this->shop->id, 'plan_id' => $plan->id, 'payment_type' => 'recurring', 'sequence' => 1,
            'amount' => 50, 'currency' => 'ILS', 'status' => 'retry_scheduled', 'attempt_count' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $contract = $this->contract($this->shop, '88', 73);
        DB::table('subscription_contracts')->where('id', $contract->id)->update([
            'next_billing_date' => CarbonImmutable::parse('2026-10-01 09:00'),
            'payment_method_gid' => 'gid://shopify/CustomerPaymentMethod/1',
            'card_exp' => '10/26',
            'customer_name' => 'Shopify Shopper',
        ]);

        $rows = Tenant::run($this->shop, fn () => $this->query()->page()['rows']);

        $this->assertCount(2, $rows);
        $this->assertSame(7 - 2, (int) $rows[0]->retries_left, 'The platform default ladder (7) minus the open slot\'s attempts.');
        $this->assertSame('shopify', $rows[1]->source);
        $this->assertNull($rows[1]->retries_left);
        $this->assertSame(UpcomingPaymentsQuery::METHOD_EXPIRING, $rows[1]->method);
    }

    public function test_search_sort_and_pages(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->upcomingPlan($this->shop, (string) (100 + $i), '2026-10-0'.(1 + $i % 5).' 09:00', $i);
        }

        Tenant::run($this->shop, function (): void {
            $q = $this->query();
            $page = $q->page('', UpcomingPaymentsQuery::SORT_AMOUNT, 2);
            $this->assertSame(30, $page['total']);
            $this->assertSame(2, $page['pages']);
            $this->assertCount(5, $page['rows']);
            $this->assertSame(5.0, (float) $page['rows'][0]->amount);

            $this->assertSame(1, $q->page('c117@example')['total']);
            $this->assertSame(1, $q->page('#sub-112')['total']);
            $this->assertSame(0, $q->page('nobody')['total']);
            $this->assertSame(0, $q->page('%')['total'], 'A LIKE wildcard is searched literally.');
        });
    }

    public function test_by_day_drill_down_link(): void
    {
        $this->upcomingPlan($this->shop, '1', '2026-09-30 09:00', 10);
        $this->upcomingPlan($this->shop, '2', '2026-09-30 15:00', 20);

        $days = Tenant::run($this->shop, fn () => PaymentsUpcoming::byDay($this->query()->byDay()));

        $this->assertCount(1, $days);
        $this->assertSame(2, $days[0]['n']);
        $this->assertStringContainsString('tableFilters', urldecode($days[0]['url']));
        $this->assertStringContainsString('[next_charge_at][from]=2026-09-30', urldecode($days[0]['url']));
    }

    public function test_screen_and_table_render(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'payments', 'upcoming')
            ->assertOk()
            ->assertSee(__('analytics/payments_upcoming.kpi.scheduled'))
            ->assertSee(__('analytics/payments_upcoming.empty.title'));

        $this->upcomingPlan($this->shop, '1', '2026-09-30 09:00', 10, 'active', null);
        $this->upcomingPlan($this->other, '9', '2026-09-30 09:00', 10);

        Livewire::test(UpcomingPaymentsTable::class, ['days' => 7, 'filters' => []])
            ->assertOk()
            ->assertSee('#SUB-1')
            ->assertDontSee('#SUB-9')
            ->assertSee(__('analytics/payments_upcoming.risk.high'))
            ->set('search', 'nobody')
            ->assertSee(__('analytics/payments_upcoming.search.none_title'))
            ->set('search', '')
            ->call('sortBy', 'risk')
            ->assertSee('#SUB-1');

        // The window and the chips come from the screen, never from the browser.
        foreach (['days' => 90, 'filters' => ['plans' => ['1']]] as $prop => $value) {
            try {
                Livewire::test(UpcomingPaymentsTable::class, ['days' => 7, 'filters' => []])->set($prop, $value);
                $this->fail("{$prop} must be #[Locked].");
            } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }

        Livewire::test(Analytics::class)->call('go', 'payments', 'upcoming')
            ->assertOk()
            ->call('setOption', 'days', '30')
            ->assertOk();
    }
}
