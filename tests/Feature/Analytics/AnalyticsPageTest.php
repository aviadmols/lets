<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\ScreenRegistry;
use App\Filament\Pages\Analytics\Screens\AnalyticsScreen;
use App\Filament\Pages\Analytics\Screens\PlaceholderScreen;
use App\Models\Shop;
use App\Models\User;
use App\Support\CsvCell;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\Feature\Analytics\Concerns\FollowsAnalyticsDownloads;
use Tests\TestCase;

/**
 * The Analytics shell: renders for an empty shop, every registered screen
 * renders, URL state is validated, a toggle re-keys only its own chart (the
 * animation-replay contract), and Export streams a formula-safe CSV.
 */
final class AnalyticsPageTest extends TestCase
{
    use BuildsSubscriptions;
    use FollowsAnalyticsDownloads;
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->makeShop('page');
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_renders_for_a_shop_with_no_data(): void
    {
        Livewire::test(Analytics::class)
            ->assertOk()
            ->assertSee(__('analytics.sections.subscribers'))
            ->assertSee(__('analytics.sections.reports'))
            ->assertSee(__('analytics/subscribers_overview.kpi.mrr'))
            ->assertSee(__('analytics.empty.no_data_title'))
            ->assertSee(__('analytics.filters.country_hint'));
    }

    public function test_every_registered_screen_renders(): void
    {
        foreach (ScreenRegistry::SECTIONS as $section => $subs) {
            foreach ($subs as $sub => $class) {
                $this->assertTrue(is_subclass_of($class, AnalyticsScreen::class), "$class must be an AnalyticsScreen.");

                $page = Livewire::test(Analytics::class)->call('go', $section, $sub)->assertOk();
                $page->assertSet('section', $section)->assertSet('sub', $sub);

                if (is_subclass_of($class, PlaceholderScreen::class)) {
                    $page->assertSee(__('analytics.placeholder.title'));
                }
            }
        }
    }

    public function test_the_reference_screen_renders_real_numbers(): void
    {
        $this->plan($this->shop, '1', 100, createdAt: CarbonImmutable::now()->subDays(60));
        $this->plan($this->shop, '2', 50, createdAt: CarbonImmutable::now()->subDays(3));

        Livewire::test(Analytics::class)
            ->assertSee(__('analytics/subscribers_overview.subscribers_trend.title'))
            ->assertSee('rc-chart__stack--pos', false)
            ->assertSee('rc-donut__slice', false)
            ->assertSee('rc-an-table', false)
            ->assertSee(__('analytics.selling_plan.none'));
    }

    public function test_url_state_is_validated(): void
    {
        Livewire::withQueryParams(['section' => 'nope', 'tab' => 'x', 'range' => '5y', 'compare' => 'sideways', 'f' => ['plans' => ['a', '3']]])
            ->test(Analytics::class)
            ->assertOk()
            ->assertSet('section', ScreenRegistry::DEFAULT_SECTION)
            ->assertSet('sub', 'overview')
            ->assertSet('range', '30d')
            ->assertSet('compare', 'previous_period')
            ->assertSet('filters', ['plans' => ['3']]);

        Livewire::withQueryParams(['section' => 'payments', 'tab' => 'recovery'])
            ->test(Analytics::class)
            ->assertSet('section', 'payments')
            ->assertSet('sub', 'recovery');
    }

    public function test_actions_reject_unknown_values(): void
    {
        Livewire::test(Analytics::class)
            ->call('setRange', 'forever')->assertSet('range', '30d')
            ->call('setRange', '90d')->assertSet('range', '90d')
            ->call('setCompare', 'nope')->assertSet('compare', 'previous_period')
            ->call('setCompare', 'none')->assertSet('compare', 'none')
            ->call('setGrain', 'subscribers_trend', 'hourly')->assertSet('grains', [])
            ->call('setGrain', 'subscribers_trend', 'weekly')->assertSet('grains', ['subscribers_trend' => 'weekly'])
            ->call('toggleFilter', 'country', 'IL')->assertSet('filters', [])
            ->call('toggleFilter', 'frequencies', 'm1')->assertSet('filters', ['frequencies' => ['m1']])
            ->call('toggleFilter', 'frequencies', 'm1')->assertSet('filters', [])
            ->set('customFrom', '2026-01-01')->set('customTo', '2026-01-31')->call('applyCustomRange')
            ->assertSet('range', 'custom')->assertSet('from', '2026-01-01')->assertSet('to', '2026-01-31');
    }

    public function test_a_toggle_rekeys_only_its_own_chart(): void
    {
        $this->plan($this->shop, '1', 100, createdAt: CarbonImmutable::now()->subDays(20));
        $this->plan($this->shop, '2', 100, createdAt: CarbonImmutable::now()->subDays(10));

        $page = Livewire::test(Analytics::class);
        $before = $this->chartKeys($page->html());

        $page->call('setGrain', 'subscribers_trend', 'weekly');
        $after = $this->chartKeys($page->html());

        $this->assertNotSame($before['subscribers_trend'], $after['subscribers_trend'], 'The toggled chart is a new node — it animates in again.');
        $this->assertSame($before['subscriptions_trend'], $after['subscriptions_trend'], 'Its neighbours keep their nodes.');
    }

    public function test_export_streams_the_screen_as_safe_csv(): void
    {
        $this->plan($this->shop, '1', 100);

        $csv = $this->fetchCsv($this->downloadLink(Livewire::test(Analytics::class)->call('export')));
        $this->assertStringContainsString(__('analytics/subscribers_overview.kpi.mrr'), $csv);

        $this->assertSame("'=SUM(A1)", CsvCell::neutralise('=SUM(A1)'));
        $this->assertSame('-12.5', CsvCell::neutralise('-12.5'), 'A negative number stays a number.');
        $this->assertSame('Book club', CsvCell::neutralise('Book club'));
    }

    public function test_a_screen_without_an_export_mints_no_link(): void
    {
        $page = Livewire::test(Analytics::class)->call('go', 'reports', 'reports')->call('export');

        $this->assertArrayNotHasKey('redirect', $page->effects);
    }

    /** @return array<string, string> chart id => wire:key */
    private function chartKeys(string $html): array
    {
        preg_match_all('/wire:key="chart-([a-z_]+)-([a-f0-9]{12})"/', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $out[$match[1]] = $match[2];
        }

        return $out;
    }
}
