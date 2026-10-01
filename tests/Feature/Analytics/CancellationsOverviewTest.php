<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Cancellations\CancellationReasons;
use App\Domain\Analytics\Cancellations\CancellationsOverviewQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\Screens\CancellationsOverview;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Support\CsvCell;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsLedger;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Cancellations › Overview: churn rate = subscribers lost ÷ active at period
 * start (the SAME walker as Subscribers › Overview), cancellation rate at
 * subscription level, orders before cancellation from the ledger, channels and
 * reasons grouped conservatively — and another shop's rows never counted,
 * in the numbers or in the export.
 */
final class CancellationsOverviewTest extends TestCase
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

    private function query(?Context $context = null): array
    {
        return Tenant::run($this->shop, fn (): array => (new CancellationsOverviewQuery($context ?? Context::default()))->get());
    }

    private function cancel(InstallmentPlan $plan, CarbonImmutable $at, string $actor = 'system', ?string $reason = null, string $to = 'cancelled'): void
    {
        $plan->forceFill(['status' => $to])->saveQuietly();
        $event = new ActivityEvent();
        $event->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'payment_id' => null,
            'actor' => $actor,
            'kind' => 'status_changed',
            'details' => array_filter(['model' => 'InstallmentPlan', 'from' => 'active', 'to' => $to, 'reason' => $reason]),
            'created_at' => $at,
        ])->save();
    }

    /** 4 subscribers active before the window; 1 leaves by hand after 3 orders, 1 by payment failure after 1. */
    private function fixture(Shop $shop): void
    {
        $born = CarbonImmutable::now()->subDays(120);
        $a = $this->plan($shop, '1', 100, createdAt: $born);
        $b = $this->plan($shop, '2', 60, createdAt: $born);
        $this->plan($shop, '3', 40, createdAt: $born);
        $this->plan($shop, '4', 40, createdAt: $born);

        foreach ([90, 60, 30] as $ago) {
            $this->charge($a, 100, CarbonImmutable::now()->subDays($ago));
        }
        $this->charge($a, 100, CarbonImmutable::now()->subDays(20), context: 'upsell'); // not an order of the subscription
        $this->charge($b, 60, CarbonImmutable::now()->subDays(40));

        $this->cancel($a, CarbonImmutable::now()->subDays(5), 'admin:7', '  Too   expensive ');
        $this->cancel($b, CarbonImmutable::now()->subDays(3), 'system', null, 'failed');
    }

    public function test_churn_and_cancellation_rates_divide_by_the_book_at_period_start(): void
    {
        $this->fixture($this->shop);

        $q = $this->query();

        $this->assertSame(2, $q['subscriber']['lost']);
        $this->assertSame(4, $q['subscriber']['start_active']);
        $this->assertSame(50.0, $q['subscriber']['rate']);
        $this->assertSame(2, $q['subscription']['cancelled']);
        $this->assertSame(50.0, $q['subscription']['rate']);
        $this->assertEqualsWithDelta(160.0, $q['subscription']['mrr_lost'], 0.01);
        $this->assertEqualsWithDelta(2.0, $q['subscription']['orders_before'], 0.01, '(3 + 1) / 2 — the upsell charge is not an order.');
        $this->assertEqualsWithDelta(160 / 240 * 100, $q['subscription']['mrr_share'], 0.1);
    }

    public function test_the_churn_rate_matches_subscribers_overview(): void
    {
        $this->fixture($this->shop);

        $overview = Tenant::run($this->shop, fn () => (new \App\Domain\Analytics\Subscribers\SubscribersOverviewQuery(Context::default()))->get());

        $this->assertSame($overview['churn']['rate'], $this->query()['subscriber']['rate']);
        $this->assertSame($overview['churn']['lost'], $this->query()['subscriber']['lost']);
    }

    /**
     * The owner's churn rule: a payment-retry lapse is churn only if the plan
     * is still lapsed at the END of the period; a lapse recovered inside the
     * period is neither churn nor reactivation; a plan lapsed at the START and
     * active again is a reactivation. Both screens agree, in the period and in
     * the comparison window.
     */
    public function test_a_payment_retry_lapse_is_churn_only_if_still_lapsed_at_period_end(): void
    {
        $born = CarbonImmutable::now()->subDays(120);
        $recovered = $this->plan($this->shop, '1', 50, createdAt: $born);   // lapses + recovers inside the period
        $returned = $this->plan($this->shop, '2', 50, createdAt: $born);    // lapsed before the period, back inside it
        $stuck = $this->plan($this->shop, '3', 50, status: 'awaiting_payment', createdAt: $born); // lapses inside, still lapsed
        $left = $this->plan($this->shop, '4', 50, createdAt: $born);        // a plain cancellation
        $this->plan($this->shop, '5', 50, createdAt: $born);

        $this->move($recovered, 'active', 'failed', CarbonImmutable::now()->subDays(20));
        $this->move($recovered, 'failed', 'active', CarbonImmutable::now()->subDays(10));
        $this->move($returned, 'active', 'failed', CarbonImmutable::now()->subDays(50));
        $this->move($returned, 'failed', 'active', CarbonImmutable::now()->subDays(10));
        $this->move($stuck, 'active', 'awaiting_payment', CarbonImmutable::now()->subDays(3));
        $this->cancel($left, CarbonImmutable::now()->subDays(5), 'admin:7');

        $q = $this->query();
        $overview = Tenant::run($this->shop, fn () => (new \App\Domain\Analytics\Subscribers\SubscribersOverviewQuery(Context::default()))->get());
        $counts = $overview['activity']['subscription']['counts'];

        $this->assertSame(2, $q['subscriber']['lost'], 'The stuck lapse + the cancellation; the recovered lapse is not churn.');
        $this->assertSame(2, $q['subscription']['cancelled']);
        $this->assertSame(4, $q['subscription']['start_active'], 'The plan lapsed since August is outside the book at the start.');
        $this->assertSame(2, $counts['cancelled']);
        $this->assertSame(1, $counts['reactivated'], 'Only the plan that was lapsed at the start.');
        $this->assertSame($overview['churn']['lost'], $q['subscriber']['lost']);
        $this->assertSame($overview['churn']['rate'], $q['subscriber']['rate']);

        // August: the plan that lapsed then is still lapsed at that window's end → churn there.
        $this->assertSame(1, $q['subscriber_previous']['lost']);
        $this->assertSame(1, $overview['churn_previous']['lost']);
        $this->assertSame(1, $overview['activity']['subscription']['previous']['cancelled']);

        // The book line still dips and recovers: today's active count is exact.
        $this->assertSame(3, $overview['kpis']['subscriptions']['value']);
    }

    public function test_channels_and_reasons_are_grouped_without_guessing(): void
    {
        $this->fixture($this->shop);
        $c = $this->plan($this->shop, '5', 50, createdAt: CarbonImmutable::now()->subDays(100));
        $this->cancel($c, CarbonImmutable::now()->subDays(2), 'system', 'customer_area');
        $d = $this->plan($this->shop, '6', 50, createdAt: CarbonImmutable::now()->subDays(100));
        $this->cancel($d, CarbonImmutable::now()->subDays(1), 'admin:7', 'too expensive');

        $q = $this->query();
        $reasons = array_column($q['reasons'], 'count', 'key');
        $channels = array_column($q['channels'], 'count', 'key');

        $this->assertSame(2, $reasons['too expensive'], 'Typed text is grouped case- and space-insensitively.');
        $this->assertSame(1, $reasons[CancellationReasons::REASON_PAYMENT_FAILED]);
        $this->assertSame(1, $reasons[CancellationReasons::REASON_NONE], 'A channel marker is never a reason.');
        $this->assertSame(2, $channels[CancellationReasons::CHANNEL_ADMIN]);
        $this->assertSame(1, $channels[CancellationReasons::CHANNEL_PAYMENT_FAILED]);
        $this->assertSame(1, $channels[CancellationReasons::CHANNEL_ACCOUNT_AREA]);
    }

    public function test_order_wise_buckets_count_completed_orders_at_cancel_time(): void
    {
        $this->fixture($this->shop);

        $rows = array_column($this->query()['order_wise'], null, 'bucket');

        $this->assertSame(1, $rows[3]['cancelled']);
        $this->assertSame(1, $rows[1]['cancelled']);
        $this->assertSame(2, $rows[0]['active'], 'The two plans still active have no completed orders.');
        $this->assertSame(100.0, $rows[3]['share'], 'Nothing active at 3 orders: the one cancelled is the whole base.');
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->other);
        $this->plan($this->shop, '9', 50, createdAt: CarbonImmutable::now()->subDays(100));

        $q = $this->query();

        $this->assertSame(0, $q['subscriber']['lost']);
        $this->assertSame(0, $q['subscription']['cancelled']);
        $this->assertSame([], $q['reasons']);
        $this->assertSame(1, array_sum(array_column($q['order_wise'], 'active')));

        $export = Tenant::run($this->shop, fn () => (new CancellationsOverview())->export(Context::default()));
        $this->assertSame([], $export['rows']);
    }

    public function test_the_export_lists_the_periods_cancellations_formula_safe(): void
    {
        $this->fixture($this->shop);
        $e = $this->plan($this->shop, '7', 50, createdAt: CarbonImmutable::now()->subDays(100));
        $this->cancel($e, CarbonImmutable::now()->subDays(1), 'admin:7', '=HYPERLINK("x")');

        $export = Tenant::run($this->shop, fn () => (new CancellationsOverview())->export(Context::default()));
        $this->assertCount(3, $export['rows']);

        $this->assertSame("'=HYPERLINK(\"x\")", CsvCell::neutralise(end($export['rows'])[8]));
    }

    public function test_a_product_filter_narrows_the_cancellations(): void
    {
        $this->fixture($this->shop);
        InstallmentPlan::withoutGlobalScopes()->where('shopify_customer_id', '1')->update(['external_product_id' => 'p-1']);

        $context = new Context(Period::default(), Filters::fromInput(['products' => ['p-1']]));
        $q = $this->query($context);

        $this->assertSame(1, $q['subscription']['cancelled']);
    }

    public function test_the_screen_renders_with_data_and_for_an_empty_shop(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'overview')
            ->assertOk()
            ->assertSee(__('analytics/cancellations_overview.kpi.churn_rate'))
            ->assertSee(__('analytics/cancellations_overview.kpi.upcoming'))
            ->assertSee(__('analytics.empty.not_tracked_title'));

        $this->fixture($this->shop);
        Cache::flush(); // the empty render above cached its numbers for 5 minutes

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'overview')
            ->assertOk()
            ->assertSee('50%')
            ->assertSee('rc-chart__stack--pos', false)
            ->assertSee('rc-donut__slice', false)
            ->assertSee(__('analytics/cancellations_overview.channel.payment_failed'))
            ->call('setOption', 'trend', 'mrr')
            ->assertOk()
            ->call('setGrain', 'churn_trend', 'weekly')
            ->assertOk();
    }
}
