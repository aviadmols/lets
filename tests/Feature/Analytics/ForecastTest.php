<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Forecast\ForecastQuery;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\Screens\Forecast;
use App\Models\PaymentLedger;
use App\Models\Shop;
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
 * Forecast: next charge dates stepped by cadence × amount × the kind's
 * trailing-90-day success rate, with a Wilson band; paused / awaiting
 * activation / comped subscriptions excluded; installment plans stop after
 * their remaining payments; another shop never counts.
 */
final class ForecastTest extends TestCase
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

    public function test_the_forecast_math_on_hand_built_groups(): void
    {
        $today = CarbonImmutable::parse('2026-09-29');
        $groups = [
            ['kind' => 'recurring', 'freq' => 'm1', 'day' => '2026-10-05', 'remaining' => null, 'amount' => 100.0, 'count' => 1],
            ['kind' => 'recurring', 'freq' => 'd14', 'day' => '2026-09-20', 'remaining' => null, 'amount' => 10.0, 'count' => 1], // overdue → today
            ['kind' => 'installments', 'freq' => 'm1', 'day' => '2026-10-01', 'remaining' => 2, 'amount' => 50.0, 'count' => 1],
        ];
        $attempts = ['recurring' => ['ok' => 9, 'bad' => 1]];

        $f = ForecastQuery::compute($groups, $attempts, [], 3, $today);

        $this->assertSame([6, 37, 67], ForecastQuery::occurrences($groups[0], $today));
        $this->assertSame([0, 14, 28, 42, 56, 70, 84], ForecastQuery::occurrences($groups[1], $today));
        $this->assertSame([2, 33], ForecastQuery::occurrences($groups[2], $today), 'Stops after the remaining payments.');

        $this->assertSame(180.0, $f['horizons'][30]['scheduled']);
        $this->assertEqualsWithDelta(162.0, $f['horizons'][30]['expected'], 0.01);
        $this->assertSame(5, $f['horizons'][30]['orders']);
        $this->assertSame(470.0, $f['horizons'][90]['scheduled']);
        $this->assertEqualsWithDelta(423.0, $f['horizons'][90]['expected'], 0.01, 'Installments borrow the pooled rate (0.9).');
        $this->assertTrue($f['rates']['kinds']['installments']['pooled']);

        $this->assertLessThan($f['horizons'][90]['expected'], $f['horizons'][90]['low']);
        $this->assertGreaterThan($f['horizons'][90]['expected'], $f['horizons'][90]['high']);
        $this->assertCount(13, $f['weeks']);
        $this->assertSame(6, end($f['weeks'])['days'], 'Week 13 holds the 85th–90th day.');
        $this->assertEqualsWithDelta(470.0, array_sum(array_column($f['weeks'], 'scheduled')), 0.01);
    }

    public function test_the_wilson_band_narrows_with_more_charges(): void
    {
        $small = ForecastQuery::wilson(9, 10);
        $large = ForecastQuery::wilson(900, 1000);

        $this->assertEqualsWithDelta(0.9, $small['rate'], 0.0001);
        $this->assertGreaterThan($large['high'] - $large['low'], $small['high'] - $small['low']);
        $this->assertLessThanOrEqual(1.0, ForecastQuery::wilson(10, 10)['high']);
    }

    private function fixture(Shop $shop): void
    {
        $next = CarbonImmutable::parse('2026-10-10 09:00:00');
        $a = $this->plan($shop, '1', 100, attributes: ['next_charge_at' => $next]);
        $this->plan($shop, '2', 100, status: 'paused', attributes: ['next_charge_at' => $next]);
        $this->plan($shop, '3', 100, status: 'awaiting_activation', attributes: ['next_charge_at' => $next]);
        $this->plan($shop, '4', 100, attributes: ['next_charge_at' => $next, 'no_charge' => true]);

        foreach (range(1, 4) as $i) {
            $this->charge($a, 100, CarbonImmutable::now()->subDays($i * 10));
        }
        $this->charge($a, 100, CarbonImmutable::now()->subDays(55), PaymentLedger::STATUS_FAILED);
        $this->charge($a, 100, CarbonImmutable::now()->subDays(200), PaymentLedger::STATUS_FAILED); // outside the 90 days
    }

    public function test_only_active_charged_subscriptions_are_forecast(): void
    {
        $this->fixture($this->shop);

        $f = Tenant::run($this->shop, fn () => (new ForecastQuery(Context::default()))->get());

        $this->assertSame(1, $f['active']);
        $this->assertSame(300.0, $f['horizons'][90]['scheduled'], '10 Oct, 10 Nov, 10 Dec.');
        $this->assertEqualsWithDelta(0.8, $f['rates']['kinds']['recurring']['rate'], 0.0001);
        $this->assertEqualsWithDelta(240.0, $f['horizons'][90]['expected'], 0.01);
    }

    public function test_another_shop_never_counts(): void
    {
        $this->fixture($this->other);

        $f = Tenant::run($this->shop, fn () => (new ForecastQuery(Context::default()))->get());

        $this->assertSame(0, $f['active']);
        $this->assertSame(0.0, $f['horizons'][90]['scheduled']);
        $this->assertNull($f['rates']['pooled']);
        $this->assertFalse($f['has_data']);

        $export = Tenant::run($this->shop, fn () => (new Forecast())->export(Context::default()));
        $this->assertSame(0.0, array_sum(array_map('floatval', array_column($export['rows'], 3))));
    }

    public function test_the_screen_renders_with_data_and_for_an_empty_shop(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'forecast')
            ->assertOk()
            ->assertSee(__('analytics/forecast_overview.empty.title'));

        $this->fixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'forecast')
            ->assertOk()
            ->assertSee(__('analytics/forecast_overview.chart.title'))
            ->assertSee('rc-chart__stack--pos', false)
            ->assertSee('₪240');
    }
}
