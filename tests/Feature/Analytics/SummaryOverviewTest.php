<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\ScreenRegistry;
use App\Filament\Pages\Analytics\Screens\SummaryOverview;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Analytics › Summary: the plain screen opens first, counts one unit (a
 * subscription) everywhere, offers today / this month / this year / specific
 * dates, and reads only its own shop.
 */
final class SummaryOverviewTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-08 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00'));
        $this->shop = $this->makeShop('summary');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function data(string $range = Period::RANGE_30): array
    {
        return Tenant::run($this->shop, fn (): array => app(SummaryOverview::class)->data(
            new Context(Period::fromInput($range), Filters::none()),
        ));
    }

    public function test_it_is_the_screen_analytics_opens_on(): void
    {
        $this->assertSame('summary', ScreenRegistry::DEFAULT_SECTION);
        $this->assertSame('summary', array_key_first(ScreenRegistry::SECTIONS));
    }

    public function test_active_count_and_monthly_revenue(): void
    {
        $this->plan($this->shop, '1', 100, createdAt: CarbonImmutable::now()->subDays(90));
        $this->plan($this->shop, '2', 120, 'yearly', createdAt: CarbonImmutable::now()->subDays(90)); // 10 / month
        $this->plan($this->shop, '3', 50, status: 'cancelled', createdAt: CarbonImmutable::now()->subDays(90));

        $d = $this->data();

        $this->assertSame('2', $d['active']);
        $this->assertStringContainsString('110', $d['mrr']);
    }

    public function test_joined_counts_subscriptions_created_in_the_period_only(): void
    {
        $this->plan($this->shop, '1', 100, createdAt: CarbonImmutable::now()->subDays(90));
        $this->plan($this->shop, '2', 100, createdAt: CarbonImmutable::now()->subDays(5));
        $this->plan($this->shop, '3', 100, createdAt: CarbonImmutable::now()->subHours(2));

        $this->assertSame('2', $this->data(Period::RANGE_30)['joined']);
        $this->assertSame('1', $this->data(Period::RANGE_TODAY)['joined']);
    }

    public function test_the_rows_by_type_add_up_to_the_active_total(): void
    {
        $this->plan($this->shop, '1', 59, createdAt: CarbonImmutable::now()->subDays(40), attributes: ['external_product_id' => '10']);
        $this->plan($this->shop, '2', 59, createdAt: CarbonImmutable::now()->subDays(40), attributes: ['external_product_id' => '10']);
        $this->plan($this->shop, '3', 30, createdAt: CarbonImmutable::now()->subDays(40), attributes: ['external_product_id' => '20']);

        $d = $this->data();

        $this->assertSame('3', $d['active']);
        $this->assertSame(3, array_sum(array_map('intval', array_column($d['by_type'], 'active'))));
        $this->assertSame('2', $d['by_type'][0]['active'], 'Biggest type first.');
    }

    public function test_another_shop_is_never_counted(): void
    {
        $other = $this->makeShop('elsewhere');
        $this->plan($other, '9', 100, createdAt: CarbonImmutable::now()->subDays(5));
        $this->plan($this->shop, '1', 100, createdAt: CarbonImmutable::now()->subDays(5));

        $d = $this->data();

        $this->assertSame('1', $d['active']);
        $this->assertSame('1', $d['joined']);
    }

    public function test_the_new_ranges(): void
    {
        $today = CarbonImmutable::parse('2026-11-08');

        $day = Period::fromInput(Period::RANGE_TODAY, null, null, null, $today);
        $this->assertSame('2026-11-08', $day->start()->format('Y-m-d'));
        $this->assertSame('2026-11-08', $day->end()->format('Y-m-d'));

        $last = Period::fromInput(Period::RANGE_LAST_MONTH, null, null, null, $today);
        $this->assertSame('2026-10-01', $last->start()->format('Y-m-d'));
        $this->assertSame('2026-10-31', $last->end()->format('Y-m-d'));

        $year = Period::fromInput(Period::RANGE_365, null, null, null, $today);
        $this->assertSame(365, $year->days());
    }

    public function test_the_page_renders_it_with_the_quick_periods(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
        $this->plan($this->shop, '1', 100, createdAt: CarbonImmutable::now()->subDays(60));
        $this->plan($this->shop, '2', 50, createdAt: CarbonImmutable::now()->subDays(3));

        Livewire::test(Analytics::class)
            ->assertOk()
            ->assertSet('section', 'summary')
            ->assertSee(__('analytics/summary_overview.kpi.active'))
            ->assertSee(__('analytics/summary_overview.by_type.title'))
            ->assertSee(__('analytics/summary_overview.lower.title'))
            ->assertSee(__('analytics/summary_overview.collection.title'))
            ->assertSee(__('analytics.range.today'))
            ->call('setRange', Period::RANGE_TODAY)->assertSet('range', Period::RANGE_TODAY)
            ->call('setRange', Period::RANGE_LAST_MONTH)->assertSet('range', Period::RANGE_LAST_MONTH)
            ->call('setRange', Period::RANGE_YTD)->assertOk();
    }
}
