<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Payments\PaymentsOverviewQuery;
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
 * Payments › Overview: ledger-truth KPIs across both rails (success % of
 * SETTLED charges), recoveries read from the Timeline's attempt history,
 * the two recovery stages, compare deltas — and another shop's charges are
 * never counted.
 */
final class PaymentsOverviewTest extends TestCase
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
        $this->shop = $this->makeShop('pay-a');
        $this->other = $this->makeShop('pay-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function overview(array $options = []): array
    {
        $context = new Context(Period::default(), Filters::none(), [], $options);

        return Tenant::run($this->shop, fn (): array => (new PaymentsOverviewQuery($context))->get());
    }

    public function test_kpis_read_the_ledger_on_both_rails(): void
    {
        $this->septemberStory($this->shop);
        $this->septemberStory($this->other); // must never leak in

        $q = $this->overview();
        $k = $q['kpis'];

        $this->assertSame(7, $k['attempted']['value']);
        // Realized P1, P2, P3, C1 = 4 · lost P5, C2 = 2 · P4 still retrying is not settled.
        $this->assertEqualsWithDelta(66.7, $k['success']['value'], 0.05);
        $this->assertSame(2, $k['recovered']['value'], 'P2 (retry) and P3 (card update) came back.');
        $this->assertSame(1, $k['under']['value']);
        $this->assertSame(2, $k['lost']['value']);
    }

    public function test_revenue_unit_sums_money(): void
    {
        $this->septemberStory($this->shop);

        $k = $this->overview(['unit' => 'revenue'])['kpis'];

        $this->assertEqualsWithDelta(100 + 50 + 80 + 60 + 70 + 73 + 40, $k['attempted']['value'], 0.01);
        $this->assertEqualsWithDelta(130.0, $k['recovered']['value'], 0.01);
        $this->assertEqualsWithDelta(110.0, $k['lost']['value'], 0.01);
        // ₪303 realized of ₪413 settled.
        $this->assertEqualsWithDelta(73.4, $k['success']['value'], 0.05);
    }

    public function test_first_attempt_and_recovery_stages(): void
    {
        $this->septemberStory($this->shop);

        $q = $this->overview();

        $this->assertSame(7, $q['first_attempt']['attempted']);
        $this->assertSame(2, $q['first_attempt']['succeeded']);
        $this->assertEqualsWithDelta(28.6, $q['first_attempt']['rate'], 0.05);

        $first = $q['first_cycle'];
        $this->assertSame(4, $first['attempted'], 'P2, P3, P4, P5 failed their first attempt.');
        $this->assertSame(1, $first['recovered']);
        $this->assertSame(1, $first['under']);
        $this->assertSame(1, $first['lost']);
        $this->assertSame(1, $first['lost_failed']);
        $this->assertEqualsWithDelta(25.0, $first['rate'], 0.01);

        $later = $q['subsequent_cycle'];
        $this->assertSame(1, $later['attempted'], 'Only P3 reached retry #2.');
        $this->assertSame(1, $later['recovered']);
        $this->assertEqualsWithDelta(100.0, $later['rate'], 0.01);
    }

    public function test_compare_delta_and_the_twelve_month_chart(): void
    {
        $this->septemberStory($this->shop);
        $old = $this->plan($this->shop, '999', 30);
        $this->charge($old, 30, [[CarbonImmutable::parse('2026-08-20 09:00'), 'ok', null]]);

        $q = $this->overview();

        $this->assertSame(1, $q['kpis']['attempted']['previous']);
        $this->assertEqualsWithDelta(600.0, $q['kpis']['attempted']['delta'], 0.01);
        $this->assertCount(12, $q['monthly']['months']);
        $this->assertSame('2026-09', end($q['monthly']['months']));
        $this->assertSame(4.0, end($q['monthly']['realized']));
        $this->assertSame(1.0, $q['monthly']['realized'][10], 'August holds the one old charge.');
    }

    public function test_a_shop_with_only_another_shops_charges_sees_nothing(): void
    {
        $this->septemberStory($this->other);

        $q = $this->overview();

        $this->assertFalse($q['has_data']);
        $this->assertSame(0, $q['kpis']['attempted']['value']);
        $this->assertNull($q['kpis']['success']['value']);
    }

    public function test_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'payments', 'overview')
            ->assertOk()
            ->assertSee(__('analytics/payments_overview.kpi.attempted'))
            ->assertSee(__('analytics/payments_overview.backup.not_tracked'))
            ->assertSee(__('analytics.empty.not_tracked_title'));

        $this->septemberStory($this->shop);
        Cache::flush(); // the empty render cached its numbers for five minutes

        Livewire::test(Analytics::class)->call('go', 'payments', 'overview')
            ->assertOk()
            ->assertSee('66.7%')
            ->assertSee(__('analytics/payments_overview.source.payplus'))
            ->assertSee(__('analytics/payments_overview.source.shopify'))
            ->call('setOption', 'unit', 'revenue')
            ->assertOk();
    }
}
