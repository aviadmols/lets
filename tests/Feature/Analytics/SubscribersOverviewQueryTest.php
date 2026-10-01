<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Subscribers\MovementSummary;
use App\Domain\Analytics\Subscribers\SubscribersOverviewQuery;
use App\Domain\Analytics\Support\Mrr;
use App\Models\Shop;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Subscribers › Overview numbers, from real rows on SQLite: both rails count,
 * one human is one subscriber, MRR normalises per cadence, history is walked
 * back from today, a person only churns when their LAST subscription goes —
 * and another shop's rows are never counted, cached or not.
 */
final class SubscribersOverviewQueryTest extends TestCase
{
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

    private function overview(?Context $context = null): array
    {
        return Tenant::run($this->shop, fn (): array => (new SubscribersOverviewQuery($context ?? Context::default()))->get());
    }

    public function test_kpis_count_both_rails_and_dedupe_the_subscriber(): void
    {
        $this->plan($this->shop, '55', 100, 'monthly');
        $this->contract($this->shop, '55', 73);          // same human, other rail
        $this->contract($this->shop, '77', 30, 'b');

        $k = $this->overview()['kpis'];

        $this->assertSame(2, $k['subscribers']['value'], 'One human on two rails is one subscriber.');
        $this->assertSame(3, $k['subscriptions']['value']);
        $this->assertSame(3, $k['quantity']['value']);
        $this->assertEqualsWithDelta(203.0, $k['mrr']['value'], 0.01);
    }

    public function test_mrr_normalises_every_cadence_to_a_month(): void
    {
        $this->plan($this->shop, '1', 120, 'yearly');               // 10 / month
        $this->plan($this->shop, '2', 7, 'weekly');                 // 30.44 / month
        $this->plan($this->shop, '3', 90, 'monthly', 2);            // every 2 months → 45
        $this->plan($this->shop, '4', 60, 'quarterly');             // 20
        $this->plan($this->shop, '5', 500, 'monthly', 1, 'active', null, ['no_charge' => true]); // comped → 0

        $mrr = Tenant::run($this->shop, fn () => (new ActiveBook(Filters::none()))->totals()['mrr']);

        $this->assertEqualsWithDelta(10 + 30.44 + 45 + 20, $mrr, 0.01);
        $this->assertEqualsWithDelta(45.0, Mrr::forPlan(90, 'monthly', 2), 0.001);
        $this->assertSame(0.0, Mrr::forPlan(500, 'monthly', 1, true));
        $this->assertEqualsWithDelta(5.0, Mrr::forContract(60, 'YEAR'), 0.001);
    }

    public function test_frequency_and_selling_plan_breakdowns(): void
    {
        $this->plan($this->shop, '1', 50, 'monthly', 3);
        $this->plan($this->shop, '2', 50, 'quarterly');   // same cadence as monthly×3
        $this->plan($this->shop, '3', 50, 'monthly');
        $this->contract($this->shop, '9', 40);

        $book = Tenant::run($this->shop, fn () => new ActiveBook(Filters::none()));
        $byFrequency = collect(Tenant::run($this->shop, fn () => $book->byFrequency()))->keyBy('key');

        $this->assertSame(2, $byFrequency['m3']['subscribers'], 'quarterly ×1 and monthly ×3 are one cadence.');
        $this->assertSame(2, $byFrequency['m1']['subscribers']); // plan 3 + the contract

        $plans = collect(Tenant::run($this->shop, fn () => $book->bySellingPlan()))->keyBy('key');
        $this->assertSame(3, $plans[ActiveBook::PLAN_NONE]['subscribers']);
        $this->assertSame(1, $plans[ActiveBook::PLAN_SHOPIFY]['subscribers']);
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $mine = $this->plan($this->shop, '1', 50, status: 'paused', createdAt: CarbonImmutable::parse('2026-09-10'));
        $this->move($mine, 'active', 'paused', CarbonImmutable::parse('2026-09-20'));

        // The other shop: more of everything, same customer ids, events in the window.
        foreach (range(1, 4) as $i) {
            $theirs = $this->plan($this->other, (string) $i, 999, status: 'cancelled', createdAt: CarbonImmutable::parse('2026-09-12'));
            $this->move($theirs, 'active', 'cancelled', CarbonImmutable::parse('2026-09-15'));
        }
        $this->contract($this->other, '1', 999);

        $q = $this->overview();

        $this->assertSame(0, $q['kpis']['subscriptions']['value'], 'Mine is paused today; theirs never count.');
        $this->assertSame(0.0, (float) $q['kpis']['mrr']['value']);
        $this->assertSame(0, $q['activity']['subscription']['counts'][MovementLog::CANCELLED]);
        $this->assertSame(1, $q['activity']['subscription']['counts'][MovementLog::PAUSED]);
        $this->assertSame(1, $q['activity']['subscription']['counts'][MovementLog::NEW]);
        $this->assertSame(0, $q['churn']['lost']);

        // …and the other shop sees only its own (the cache is per shop).
        $theirs = Tenant::run($this->other, fn () => (new SubscribersOverviewQuery(Context::default()))->get());
        $this->assertSame(1, $theirs['kpis']['subscriptions']['value']);
        $this->assertSame(4, $theirs['activity']['subscription']['counts'][MovementLog::CANCELLED]);
    }

    public function test_no_tenant_means_nothing(): void
    {
        $this->plan($this->shop, '1', 50);
        Tenant::clear();

        $q = (new SubscribersOverviewQuery(Context::default()))->get();

        $this->assertSame(0, $q['kpis']['subscriptions']['value']);
        $this->assertFalse($q['has_data']);
    }

    public function test_history_is_walked_back_so_the_line_ends_on_the_kpi(): void
    {
        // Born before the window and still active.
        $this->plan($this->shop, '1', 50, createdAt: CarbonImmutable::parse('2026-06-01'));
        // Born inside the window.
        $this->plan($this->shop, '2', 50, createdAt: CarbonImmutable::parse('2026-09-20'));
        // Cancelled inside the window.
        $gone = $this->plan($this->shop, '3', 50, status: 'cancelled', createdAt: CarbonImmutable::parse('2026-05-01'));
        $this->move($gone, 'active', 'cancelled', CarbonImmutable::parse('2026-09-10'));

        $q = $this->overview();
        $line = $q['series'][SubscribersOverviewQuery::CHART_SUBSCRIPTIONS]['active'];

        $this->assertSame(2, end($line), 'The line ends on today\'s count.');
        $this->assertSame(2, $q['kpis']['subscriptions']['value']);
        // At the start of the window: plans 1 and 3 were live.
        $this->assertSame(2, $q['churn']['start_active']);
        // At the comparison end (30 Aug): plans 1 and 3.
        $this->assertSame(2, $q['kpis']['subscriptions']['previous']);
        $this->assertSame(1, $q['activity']['subscription']['counts'][MovementLog::NEW]);
        $this->assertSame(1, $q['activity']['subscription']['counts'][MovementLog::CANCELLED]);
    }

    public function test_movements_are_classified_from_the_state_machine(): void
    {
        $this->assertSame(MovementLog::RESUMED, MovementLog::classify('paused', 'active'));
        $this->assertSame(MovementLog::REACTIVATED, MovementLog::classify('failed', 'active'));
        $this->assertSame(MovementLog::NEW, MovementLog::classify('awaiting_first_payment', 'active'));
        $this->assertSame(MovementLog::PAUSED, MovementLog::classify('active', 'paused'));
        $this->assertSame(MovementLog::CANCELLED, MovementLog::classify('active', 'cancelled'));
        $this->assertSame(MovementLog::CANCELLED, MovementLog::classify('active', 'failed'), 'Involuntary churn — a candidate, see countsIn().');
        $this->assertSame(MovementLog::EXPIRED, MovementLog::classify('active', 'completed'));
        $this->assertNull(MovementLog::classify('paused', 'cancelled'), 'Not in the book before or after.');
        $this->assertNull(MovementLog::classify('draft', 'cancelled'));

        $this->assertSame(MovementLog::DUNNING_OUT, MovementLog::dunning('active', 'awaiting_payment'));
        $this->assertSame(MovementLog::DUNNING_IN, MovementLog::dunning('failed', 'active'));
        $this->assertNull(MovementLog::dunning('active', 'cancelled'), 'A voluntary cancellation is not dunning.');
    }

    /** The owner's churn rule, as pure arithmetic over window bounds. */
    public function test_a_dunning_move_counts_by_where_the_plan_stands_at_the_window_edges(): void
    {
        [$from, $to] = ['2026-09-01 00:00:00', '2026-09-30 23:59:59'];
        $out = static fn (string $at, ?string $back): array => ['at' => $at, 'dunning' => MovementLog::DUNNING_OUT, 'paired_at' => $back];
        $in = static fn (string $at, ?string $lapsed): array => ['at' => $at, 'dunning' => MovementLog::DUNNING_IN, 'paired_at' => $lapsed];

        $this->assertTrue(MovementLog::countsIn($out('2026-09-10 10:00:00', null), $from, $to), 'Never recovered → churn.');
        $this->assertTrue(MovementLog::countsIn($out('2026-09-10 10:00:00', '2026-10-02 10:00:00'), $from, $to), 'Recovered only after the end → churn of this window.');
        $this->assertFalse(MovementLog::countsIn($out('2026-09-10 10:00:00', '2026-09-20 10:00:00'), $from, $to), 'Recovered inside → not churn.');
        $this->assertFalse(MovementLog::countsIn($in('2026-09-20 10:00:00', '2026-09-10 10:00:00'), $from, $to), '…and its recovery is not a reactivation.');
        $this->assertTrue(MovementLog::countsIn($in('2026-09-20 10:00:00', '2026-08-10 10:00:00'), $from, $to), 'Lapsed at the start → reactivated.');
        $this->assertTrue(MovementLog::countsIn($in('2026-09-20 10:00:00', null), $from, $to), 'Lapse older than the log → reactivated.');
        $this->assertTrue(MovementLog::countsIn(['at' => '2026-09-10 10:00:00', 'dunning' => null], $from, $to), 'Any other move counts where it happened.');
        $this->assertFalse(MovementLog::countsIn(['at' => '2026-08-31 23:59:59', 'dunning' => null], $from, $to));
    }

    public function test_a_plan_activated_by_event_is_new_once(): void
    {
        $plan = $this->plan($this->shop, '1', 50, createdAt: CarbonImmutable::parse('2026-09-05'));
        $this->move($plan, 'awaiting_first_payment', 'active', CarbonImmutable::parse('2026-09-06'));

        $counts = $this->overview()['activity']['subscription']['counts'];

        $this->assertSame(1, $counts[MovementLog::NEW], 'Its activation is its arrival — not also its creation.');
    }

    public function test_a_subscriber_churns_only_when_their_last_subscription_goes(): void
    {
        // Customer 1 holds two; cancels one → a cancelled SUBSCRIPTION, no churned SUBSCRIBER.
        $this->plan($this->shop, '1', 50, createdAt: CarbonImmutable::parse('2026-01-01'));
        $one = $this->plan($this->shop, '1', 50, status: 'cancelled', createdAt: CarbonImmutable::parse('2026-01-01'));
        $this->move($one, 'active', 'cancelled', CarbonImmutable::parse('2026-09-15'));
        // Customer 2 cancels their only one → churned subscriber.
        $two = $this->plan($this->shop, '2', 50, status: 'cancelled', createdAt: CarbonImmutable::parse('2026-01-01'));
        $this->move($two, 'active', 'cancelled', CarbonImmutable::parse('2026-09-16'));

        $q = $this->overview();

        $this->assertSame(2, $q['activity']['subscription']['counts'][MovementLog::CANCELLED]);
        $this->assertSame(1, $q['activity']['subscriber']['counts'][MovementLog::CANCELLED]);
        $this->assertSame(1, $q['churn']['lost']);
        $this->assertSame(2, $q['churn']['start_active']);
        $this->assertSame(50.0, $q['churn']['rate']);
        $this->assertEqualsWithDelta(100.0, $q['churn']['cancelled_mrr'], 0.01);
    }

    public function test_zero_day_churn_is_a_same_day_cancellation(): void
    {
        $plan = $this->plan($this->shop, '1', 50, status: 'cancelled', createdAt: CarbonImmutable::parse('2026-09-20 09:00'));
        $this->move($plan, 'active', 'cancelled', CarbonImmutable::parse('2026-09-20 15:00'));

        $churn = $this->overview()['churn'];

        $this->assertSame(1, $churn['lost']);
        $this->assertSame(1, $churn['zero_day']);
        $this->assertSame(100.0, $churn['zero_day_share']);
    }

    public function test_crossings_are_found_by_walking_back_from_today(): void
    {
        $rows = [
            ['at' => '2026-09-01 10:00:00', 'day' => '2026-09-01', 'sub' => 'p:1', 'key' => 'a', 'type' => 'new', 'dir' => 1, 'qty' => 1, 'mrr' => 1.0, 'born' => '2026-09-01', 'actor' => 's'],
            ['at' => '2026-09-02 10:00:00', 'day' => '2026-09-02', 'sub' => 'p:2', 'key' => 'a', 'type' => 'new', 'dir' => 1, 'qty' => 1, 'mrr' => 1.0, 'born' => '2026-09-02', 'actor' => 's'],
            ['at' => '2026-09-03 10:00:00', 'day' => '2026-09-03', 'sub' => 'p:1', 'key' => 'a', 'type' => 'cancelled', 'dir' => -1, 'qty' => 1, 'mrr' => 1.0, 'born' => '2026-09-01', 'actor' => 's'],
        ];

        $crossings = MovementSummary::crossings($rows, ['a' => 1]);

        $this->assertCount(1, $crossings, 'Only the first arrival crosses 0 → 1; the cancel leaves one behind.');
        $this->assertSame('p:1', $crossings[0]['sub']);
        $this->assertSame('new', $crossings[0]['type']);
    }

    public function test_filters_narrow_both_rails_consistently(): void
    {
        $this->plan($this->shop, '1', 50, 'monthly', 2);
        $this->plan($this->shop, '2', 50, 'monthly');
        $this->contract($this->shop, '3', 40);

        $byFrequency = new Context(Period::default(), Filters::fromInput(['frequencies' => ['m1']]));
        $k = $this->overview($byFrequency)['kpis'];
        $this->assertSame(2, $k['subscriptions']['value'], 'Monthly plan + monthly contract.');

        $byPlan = new Context(Period::default(), Filters::fromInput(['plans' => ['999']]));
        $this->assertSame(0, $this->overview($byPlan)['kpis']['subscriptions']['value'], 'A selling-plan filter excludes contracts.');
    }

    public function test_compare_deltas(): void
    {
        $this->plan($this->shop, '1', 50, createdAt: CarbonImmutable::parse('2026-06-01'));
        $this->plan($this->shop, '2', 50, createdAt: CarbonImmutable::parse('2026-09-20'));

        $k = $this->overview()['kpis'];
        $this->assertSame(1, $k['subscriptions']['previous']);
        $this->assertSame(100.0, $k['subscriptions']['delta']);

        $none = new Context(Period::fromInput('30d', 'none'), Filters::none());
        $this->assertNull($this->overview($none)['kpis']['subscriptions']['delta']);
    }
}
