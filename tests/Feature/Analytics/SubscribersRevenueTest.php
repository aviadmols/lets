<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\RevenueQuery;
use App\Filament\Pages\Analytics;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsLedger;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Subscribers › Revenue: checkout = a plan's first realised charge, the rest
 * (+ every Shopify renewal) recurring; money is net of refunds; failed and
 * non-subscription contexts never count; the comparison window feeds the
 * deltas; another shop is never counted; the screen renders empty and full,
 * with the non-subscriber cards in the not-tracked state.
 */
final class SubscribersRevenueTest extends TestCase
{
    use BuildsLedger;
    use BuildsSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    private Shop $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('a');
        $this->other = $this->makeShop('b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function query(Shop $shop): array
    {
        return Tenant::run($shop, fn (): array => (new RevenueQuery(Context::default()))->get());
    }

    private function fixture(Shop $shop): void
    {
        $at = static fn (string $d): CarbonImmutable => CarbonImmutable::parse($d.' 10:00');

        $a = $this->plan($shop, '1', 50, createdAt: $at('2026-08-10'));
        $this->charge($a, 50, $at('2026-08-10'));                              // checkout, in the comparison window
        $this->charge($a, 50, $at('2026-09-10'));                              // recurring
        $this->charge($a, 50, $at('2026-09-20'), refunded: 20);                // recurring, net 30
        $this->charge($a, 50, $at('2026-09-22'), PaymentLedger::STATUS_FAILED); // never money

        $b = $this->plan($shop, '2', 80, createdAt: $at('2026-09-05'));
        $this->charge($b, 80, $at('2026-09-05'));                              // checkout
        $this->charge($b, 25, $at('2026-09-06'), context: PaymentLedger::CONTEXT_UPSELL); // not a subscription charge

        $x = $this->contract($shop, '3', 60, createdAt: $at('2026-08-01'));
        $this->attempt($x, $at('2026-09-15'));                                  // recurring at the contract's amount
        $this->attempt($x, $at('2026-09-16'), SubscriptionBillingAttempt::STATUS_FAILED);
    }

    public function test_recurring_vs_checkout_net_of_refunds_with_compare(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);

        $this->assertSame(['orders' => 3, 'revenue' => 140.0], $q['totals']['recurring']);
        $this->assertSame(['orders' => 1, 'revenue' => 80.0], $q['totals']['checkout']);
        $this->assertSame(['orders' => 4, 'revenue' => 220.0], $q['totals']['all']);
        $this->assertSame(['orders' => 1, 'revenue' => 50.0], $q['previous']['checkout']);
        $this->assertSame(340.0, RevenueQuery::delta($q, 'all'));
        $this->assertNull(RevenueQuery::delta($q, 'recurring'), 'No recurring revenue to compare with.');
        $this->assertEqualsWithDelta(140.0, array_sum($q['series']['revenue']['recurring']), 0.001, 'The series sums to the KPI.');
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->other);
        $mine = $this->plan($this->shop, '1', 10, createdAt: CarbonImmutable::parse('2026-09-01'));
        $this->charge($mine, 10, CarbonImmutable::parse('2026-09-01 10:00'));

        $q = $this->query($this->shop);

        $this->assertSame(['orders' => 1, 'revenue' => 10.0], $q['totals']['all']);
        $this->assertSame(220.0, $this->query($this->other)['totals']['all']['revenue']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'revenue')
            ->assertOk()
            ->assertSee(__('analytics/subscribers_revenue.customers.not_tracked'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->fixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'revenue')
            ->assertOk()
            ->assertSee('rc-chart__line', false)
            ->assertSee(__('analytics/subscribers_revenue.chart.aov'))
            ->assertSee('220');
    }
}
