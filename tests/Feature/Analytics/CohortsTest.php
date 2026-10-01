<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Cohorts\CohortsQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Filament\Pages\Analytics;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsPayments;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Cohorts: retention measured from each member's own join moment (Month 0 is
 * 100%), a subscriber's cohort is their FIRST subscription (earlier joiners
 * are left out), orders/revenue per month since joining net of refunds,
 * cumulative averages — and another shop's members never join a cohort.
 */
final class CohortsTest extends TestCase
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
        $this->shop = $this->makeShop('coh-a');
        $this->other = $this->makeShop('coh-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function cohorts(string $metric = 'subscriber_retention', array $more = []): array
    {
        $context = new Context(Period::default(), Filters::none(), [], ['metric' => $metric] + $more);

        return Tenant::run($this->shop, fn (): array => (new CohortsQuery($context))->get());
    }

    /** @return array<string, array<string, mixed>> month => row */
    private function byMonth(array $q): array
    {
        return array_column($q['rows'], null, 'month');
    }

    private function june(Shop $shop): array
    {
        $a = $this->plan($shop, $shop->id.'1', 50, 'monthly', 1, 'active', CarbonImmutable::parse('2026-06-10 10:00'));
        $b = $this->plan($shop, $shop->id.'2', 40, 'monthly', 1, 'cancelled', CarbonImmutable::parse('2026-06-15 10:00'));
        $this->move($b, 'active', 'cancelled', CarbonImmutable::parse('2026-08-20 10:00'));

        return [$a, $b];
    }

    public function test_retention_is_measured_from_each_members_join(): void
    {
        $this->june($this->shop);
        $this->june($this->other);

        $q = $this->cohorts();
        $june = $this->byMonth($q)['2026-06'];

        $this->assertSame(12, $q['columns'], 'Oct 2025, the oldest cohort, has reached Month 11.');
        $this->assertSame(2, $june['size']);
        $this->assertSame([2, 2, 2, 1], $june['values'], 'B leaves on 20 Aug — after its month-2 mark (15 Aug), before month 3.');
        $this->assertSame([100.0, 100.0, 100.0, 50.0], $june['cells']);
        $this->assertSame(0, $this->byMonth($q)['2026-07']['size']);
        $this->assertCount(12, $q['rows'], 'Last 12 months, oldest first.');
        $this->assertSame('2025-10', $q['rows'][0]['month']);
    }

    public function test_a_returning_person_is_not_a_new_subscriber(): void
    {
        $this->plan($this->shop, 'old', 30, 'monthly', 1, 'cancelled', CarbonImmutable::parse('2025-01-05 10:00'));
        $this->plan($this->shop, 'old', 30, 'monthly', 1, 'active', CarbonImmutable::parse('2026-07-05 10:00'));

        $this->assertSame(0, $this->byMonth($this->cohorts())['2026-07']['size']);
        $this->assertSame(1, $this->byMonth($this->cohorts('subscription_retention'))['2026-07']['size'],
            'At subscription level the new subscription still joins July.');
    }

    public function test_orders_revenue_and_ltv(): void
    {
        [$a, $b] = $this->june($this->shop);
        $this->ledger($a, 50, 'succeeded', CarbonImmutable::parse('2026-06-10 10:01'), 'a1');
        $this->ledger($a, 50, 'succeeded', CarbonImmutable::parse('2026-07-10 10:00'), 'a2');
        $this->ledger($a, 50, 'refunded', CarbonImmutable::parse('2026-08-10 10:00'), 'a3', null, 20);
        $this->ledger($a, 50, 'failed', CarbonImmutable::parse('2026-09-10 10:00'), 'a4');
        $this->ledger($b, 40, 'succeeded', CarbonImmutable::parse('2026-06-15 10:01'), 'b1');

        $orders = $this->byMonth($this->cohorts('subscription_orders'))['2026-06'];
        $this->assertSame([2, 1, 1, 0], $orders['values']);
        $this->assertSame([100.0, 50.0, 50.0, 0.0], $orders['cells'], '% of the cohort\'s own Month 0.');

        $revenue = $this->byMonth($this->cohorts('subscription_revenue'))['2026-06'];
        $this->assertSame([90.0, 50.0, 30.0, 0.0], $revenue['values'], 'The refund is netted out of August.');

        $ltv = $this->byMonth($this->cohorts('subscriber_ltv'))['2026-06'];
        $this->assertSame([45.0, 70.0, 85.0, 85.0], $ltv['values']);

        $avg = $this->byMonth($this->cohorts('subscriber_avg_orders'))['2026-06'];
        $this->assertSame([1.0, 1.5, 2.0, 2.0], $avg['values']);
    }

    public function test_span_option(): void
    {
        $this->june($this->shop);

        $q = $this->cohorts('subscriber_retention', ['span' => '3']);

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($q['rows'], 'month'));
        $this->assertFalse($q['has_data'], 'June is outside a 3-month window.');
    }

    public function test_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'cohorts')
            ->assertOk()
            ->assertSee(__('analytics/cohorts_overview.empty.title'));

        Cache::flush();
        $this->june($this->shop);

        Livewire::test(Analytics::class)->call('go', 'cohorts')
            ->assertOk()
            ->assertSee(__('analytics/cohorts_overview.month', ['n' => 3]))
            ->assertSee('50%')
            ->call('setOption', 'mode', 'number')
            ->assertOk()
            ->call('setOption', 'metric', 'subscription_ltv')
            ->assertSee(__('analytics/cohorts_overview.cumulative_note'));
    }
}
