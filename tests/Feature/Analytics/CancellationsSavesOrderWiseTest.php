<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Cancellations\OrderWiseChurnQuery;
use App\Domain\Analytics\Cancellations\SavesQuery;
use App\Domain\Analytics\Context;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\Screens\CancellationsOrderWise;
use App\Filament\Pages\Analytics\Screens\CancellationsSaves;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
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
 * Cancellations › Saves and › Order-wise churn.
 *
 * Saves: the cancellation flow is not tracked and the screen says so; what IS
 * real — recovered-after-failure and came-back-after-cancelling — is counted.
 * Order-wise: churn per completed-order bucket, the source filter, and the
 * reason table. Both: another shop's rows never count, export included.
 */
final class CancellationsSavesOrderWiseTest extends TestCase
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

    private function event(InstallmentPlan $plan, string $from, string $to, CarbonImmutable $at, string $actor = 'system', ?string $reason = null): void
    {
        $event = new ActivityEvent();
        $event->forceFill([
            'shop_id' => $plan->shop_id, 'plan_id' => $plan->getKey(), 'payment_id' => null, 'actor' => $actor,
            'kind' => 'status_changed', 'details' => array_filter(['model' => 'InstallmentPlan', 'from' => $from, 'to' => $to, 'reason' => $reason]),
            'created_at' => $at,
        ])->save();
    }

    private function savesFixture(Shop $shop): void
    {
        $now = CarbonImmutable::now();
        // Recovered: failed → active inside the window.
        $lapsed = $this->plan($shop, '1', 80, createdAt: $now->subDays(200));
        $this->event($lapsed, 'active', 'failed', $now->subDays(12));
        $this->event($lapsed, 'failed', 'active', $now->subDays(6));

        // Came back: cancelled long ago, a NEW plan inside the window.
        $old = $this->plan($shop, '2', 50, status: 'cancelled', createdAt: $now->subDays(300));
        $this->event($old, 'active', 'cancelled', $now->subDays(100), 'customer_area', 'customer_area');
        $this->plan($shop, '2', 55, createdAt: $now->subDays(4));

        // A first-time subscriber is NOT a comeback.
        $this->plan($shop, '3', 40, createdAt: $now->subDays(3));
    }

    public function test_saves_count_only_what_is_recorded(): void
    {
        $this->savesFixture($this->shop);

        $q = Tenant::run($this->shop, fn () => (new SavesQuery(Context::default()))->get());

        $this->assertSame(1, $q['totals']['reactivated']);
        $this->assertEqualsWithDelta(80.0, $q['totals']['reactivated_mrr'], 0.01);
        $this->assertSame(1, $q['totals']['returned']);
        $this->assertEqualsWithDelta(55.0, $q['totals']['returned_mrr'], 0.01);
        $this->assertCount(2, $q['recent']);
    }

    public function test_saves_never_count_another_shop(): void
    {
        $this->savesFixture($this->other);

        $q = Tenant::run($this->shop, fn () => (new SavesQuery(Context::default()))->get());
        $this->assertSame(0, $q['totals']['reactivated'] + $q['totals']['returned']);

        $export = Tenant::run($this->shop, fn () => (new CancellationsSaves())->export(Context::default()));
        $this->assertSame([], $export['rows']);
    }

    public function test_the_saves_screen_renders_the_flow_as_not_tracked(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'saves')
            ->assertOk()
            ->assertSee(__('analytics/cancellations_saves.flow.empty_title'))
            ->assertSee(__('analytics/cancellations_saves.kpi.save_rate'))
            ->assertSee('rc-an-empty--not-tracked', false);

        $this->savesFixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'saves')
            ->assertOk()
            ->assertSee(__('analytics/cancellations_saves.type.returned'))
            ->assertSee('rc-chart__stack--pos', false);
    }

    private function orderFixture(Shop $shop): void
    {
        $now = CarbonImmutable::now();
        $a = $this->plan($shop, '1', 100, createdAt: $now->subDays(200));
        foreach ([150, 120, 90] as $ago) {
            $this->charge($a, 100, $now->subDays($ago));
        }
        $a->forceFill(['status' => 'cancelled'])->saveQuietly();
        $this->event($a, 'active', 'cancelled', $now->subDays(5), 'admin:3', 'Moving abroad');

        $b = $this->plan($shop, '2', 60, createdAt: $now->subDays(200));
        $this->charge($b, 60, $now->subDays(100));
        $b->forceFill(['status' => 'failed'])->saveQuietly();
        $this->event($b, 'active', 'failed', $now->subDays(2));

        $c = $this->plan($shop, '3', 40, createdAt: $now->subDays(200));
        foreach ([150, 120, 90] as $ago) {
            $this->charge($c, 40, $now->subDays($ago));
        }
    }

    public function test_order_wise_churn_buckets_and_source_filter(): void
    {
        $this->orderFixture($this->shop);

        $all = Tenant::run($this->shop, fn () => (new OrderWiseChurnQuery(Context::default()))->get('all', 'all'));
        $rows = array_column($all['by_orders'], null, 'bucket');
        $this->assertSame(1, $rows[3]['cancelled']);
        $this->assertSame(1, $rows[3]['active']);
        $this->assertSame(50.0, $rows[3]['share']);
        $this->assertSame(1, $rows[1]['cancelled']);
        $this->assertSame(2, $all['totals']['churned']);
        $this->assertSame(3, $all['totals']['subscriptions']);
        $this->assertSame(2.0, $all['totals']['orders_before']);

        $admin = Tenant::run($this->shop, fn () => (new OrderWiseChurnQuery(Context::default()))->get('admin', 'all'));
        $this->assertSame(1, $admin['totals']['churned']);
        $this->assertSame('moving abroad', $admin['reasons'][0]['key']);
        $this->assertSame('Moving abroad', $admin['reasons'][0]['text']);

        $failed = Tenant::run($this->shop, fn () => (new OrderWiseChurnQuery(Context::default()))->get('payment_failed', '1'));
        $this->assertSame(1, $failed['totals']['churned']);
        $this->assertSame(1, array_sum(array_merge(...array_column($failed['reason_trend']['series'], 'values'))));
    }

    public function test_order_wise_never_counts_another_shop(): void
    {
        $this->orderFixture($this->other);

        $q = Tenant::run($this->shop, fn () => (new OrderWiseChurnQuery(Context::default()))->get('all', 'all'));
        $this->assertSame(0, $q['totals']['churned']);
        $this->assertSame(0, $q['totals']['subscriptions']);

        $export = Tenant::run($this->shop, fn () => (new CancellationsOrderWise())->export(Context::default()));
        $this->assertSame(0, array_sum(array_column($export['rows'], 2)));
    }

    public function test_the_order_wise_screen_renders_and_its_options_toggle(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'order_wise')
            ->assertOk()
            ->assertSee(__('analytics/cancellations_order_wise.by_orders.title'));

        $this->orderFixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'order_wise')
            ->assertOk()
            ->assertSee('Moving abroad')
            ->call('setOption', 'source', 'payment_failed')
            ->assertSet('options.source', 'payment_failed')
            ->assertDontSee('Moving abroad')
            ->call('setOption', 'orders', '1')
            ->assertSet('options.orders', '1')
            ->call('setOption', 'source', 'nonsense')
            ->assertSet('options.source', 'payment_failed');
    }
}
