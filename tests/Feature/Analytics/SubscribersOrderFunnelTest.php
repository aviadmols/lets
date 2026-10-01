<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\OrderFunnelQuery;
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
 * Subscribers › Order funnel: today's active subscriptions by completed
 * orders (both rails), each attempted cycle's outcome at its order number,
 * the dunning split, tenant isolation, and the screen rendering empty and
 * full with stage 1 marked not tracked.
 */
final class SubscribersOrderFunnelTest extends TestCase
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

    private function query(Shop $shop, string $mode = OrderFunnelQuery::MODE_ALL): array
    {
        return Tenant::run($shop, fn (): array => (new OrderFunnelQuery(Context::default()))->get($mode));
    }

    private function fixture(Shop $shop): void
    {
        $at = static fn (string $d): CarbonImmutable => CarbonImmutable::parse($d.' 10:00');

        $a = $this->plan($shop, '1', 50, createdAt: $at('2026-07-01'));
        $this->charge($a, 50, $at('2026-07-01'));                                   // #1 (before the window)
        $this->charge($a, 50, $at('2026-08-01'));                                   // #2
        $this->charge($a, 50, $at('2026-09-01'));                                   // #3 success
        $this->charge($a, 50, $at('2026-09-28'), PaymentLedger::STATUS_RETRY_SCHEDULED); // #4 retrying

        $b = $this->plan($shop, '2', 30, createdAt: $at('2026-09-05'));
        $this->charge($b, 30, $at('2026-09-05'), PaymentLedger::STATUS_FAILED);    // #1 failed

        $c = $this->plan($shop, '3', 40, status: 'cancelled', createdAt: $at('2026-09-10'));
        $this->charge($c, 40, $at('2026-09-10'));                                   // #1 success (not active today)

        $x = $this->contract($shop, '4', 60, createdAt: $at('2026-08-15'));
        $this->attempt($x, $at('2026-09-15'));                                       // order #2 success
        $this->attempt($x, $at('2026-09-20'), SubscriptionBillingAttempt::STATUS_FAILED); // order #3 failed
    }

    public function test_order_wise_active_and_outcomes_by_order_number(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);

        $this->assertSame([0 => 1, 2 => 1, 3 => 1], $q['order_wise'], 'B: none realised · contract: checkout + 1 renewal · A: three.');
        $this->assertSame(['success' => 3, 'retrying' => 1, 'failed' => 2, 'pending' => 0], $q['funnel']);

        $this->assertSame(1, $q['leakage'][1]['success']);
        $this->assertSame(1, $q['leakage'][1]['failed']);
        $this->assertSame(1, $q['leakage'][2]['success'], 'The contract\'s first renewal is its order #2.');
        $this->assertSame(['success' => 1, 'retrying' => 0, 'failed' => 1, 'pending' => 0], $q['leakage'][3]);
        $this->assertSame(1, $q['leakage'][4]['retrying']);
    }

    public function test_dunning_and_non_dunning_split_the_attempts(): void
    {
        $this->fixture($this->shop);

        $dunning = $this->query($this->shop, OrderFunnelQuery::MODE_DUNNING)['funnel'];
        $clean = $this->query($this->shop, OrderFunnelQuery::MODE_NON_DUNNING)['funnel'];

        $this->assertSame(['success' => 0, 'retrying' => 1, 'failed' => 2, 'pending' => 0], $dunning);
        $this->assertSame(['success' => 3, 'retrying' => 0, 'failed' => 0, 'pending' => 0], $clean);
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->other);
        $mine = $this->plan($this->shop, '1', 50, createdAt: CarbonImmutable::parse('2026-09-02'));
        $this->charge($mine, 50, CarbonImmutable::parse('2026-09-02 10:00'));

        $q = $this->query($this->shop);

        $this->assertSame([1 => 1], $q['order_wise']);
        $this->assertSame(['success' => 1, 'retrying' => 0, 'failed' => 0, 'pending' => 0], $q['funnel']);
        $this->assertSame(3, $this->query($this->other)['funnel']['success']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'order_funnel')
            ->assertOk()
            ->assertSee(__('analytics/subscribers_order_funnel.funnel.title'))
            ->assertSee(__('analytics/subscribers_order_funnel.funnel.stage_1_not_tracked'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->fixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'order_funnel')
            ->assertOk()
            ->assertSee('rc-funnel__shape', false)
            ->assertSee('rc-chart__stack--pos', false)
            ->assertSee(__('analytics/subscribers_order_funnel.leakage.all'))
            ->call('setOption', 'orders', 'dunning')
            ->assertOk()
            ->call('setOption', 'unit', 'percent')
            ->assertSee('66.7%'); // dunning totals: 2 of 3 failed for good
    }
}
