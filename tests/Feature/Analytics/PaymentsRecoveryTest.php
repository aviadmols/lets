<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Payments\DeclineReason;
use App\Domain\Analytics\Payments\PaymentJourneys;
use App\Domain\Analytics\Payments\PaymentsRecoveryQuery;
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
 * Payments › Recovery: retry vs card-update attribution, recovery rate,
 * recovery by retry number and by decline reason, the journey rules
 * themselves — and tenant isolation.
 */
final class PaymentsRecoveryTest extends TestCase
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
        $this->shop = $this->makeShop('rec-a');
        $this->other = $this->makeShop('rec-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function recovery(): array
    {
        return Tenant::run($this->shop, fn (): array => (new PaymentsRecoveryQuery(Context::default()))->get());
    }

    public function test_recovery_rate_and_the_retry_vs_card_update_split(): void
    {
        $this->septemberStory($this->shop);
        $this->septemberStory($this->other);

        $r = $this->recovery()['recovery'];

        $this->assertSame(4, $r['failed'], 'Shopify attempts carry no retry history and stay out.');
        $this->assertSame(2, $r['recovered']);
        $this->assertSame(1, $r['retry']);
        $this->assertSame(1, $r['card_update'], 'P3 had a card_updated between its first failure and its success.');
        $this->assertEqualsWithDelta(50.0, $r['rate'], 0.01);
        $this->assertEqualsWithDelta(130.0, $r['realized'], 0.01);
    }

    public function test_a_card_update_after_the_success_is_not_the_reason_it_paid(): void
    {
        $plan = $this->plan($this->shop, '42', 30);
        $this->charge($plan, 30, [
            [CarbonImmutable::parse('2026-09-10 09:00'), 'fail', self::DECLINE_FUNDS],
            [CarbonImmutable::parse('2026-09-11 09:00'), 'ok', null],
        ]);
        $this->cardUpdated($plan, CarbonImmutable::parse('2026-09-12 09:00'));

        $this->assertSame(1, $this->recovery()['recovery']['retry']);
    }

    public function test_by_retry_number_and_by_reason(): void
    {
        $this->septemberStory($this->shop);

        $q = $this->recovery();

        $this->assertSame(1, $q['by_retry'][0]['retry']);
        $this->assertSame(4, $q['by_retry'][0]['attempts'], 'P2, P3, P5 had retry #1; P4 is waiting for it.');
        $this->assertSame(1, $q['by_retry'][0]['recovered']);
        $this->assertSame(2, $q['by_retry'][1]['retry']);
        $this->assertSame(1, $q['by_retry'][1]['attempts']);
        $this->assertSame(1, $q['by_retry'][1]['card_update']);

        $reasons = array_column($q['by_reason'], null, 'reason');
        $this->assertSame(2, $reasons[DeclineReason::BLOCKED]['failed']);
        $this->assertSame(1, $reasons[DeclineReason::BLOCKED]['recovered']);
        $this->assertSame(1, $reasons[DeclineReason::BLOCKED]['under']);
        $this->assertSame(2, $reasons[DeclineReason::INSUFFICIENT_FUNDS]['failed']);
        $this->assertEqualsWithDelta(50.0, $reasons[DeclineReason::INSUFFICIENT_FUNDS]['rate'], 0.01);
    }

    public function test_a_journey_begun_before_the_window_is_not_cut_in_half(): void
    {
        $plan = $this->plan($this->shop, '43', 30);
        // First attempt 40 days before the period's comparison window even starts.
        $this->charge($plan, 30, [
            [CarbonImmutable::parse('2026-07-01 09:00'), 'fail', self::DECLINE_FUNDS],
            [CarbonImmutable::parse('2026-09-15 09:00'), 'ok', null],
        ]);

        $q = $this->recovery();

        $this->assertFalse($q['has_data'], 'Its retry in September is not a new first attempt.');
    }

    /**
     * The SQL pre-grouping (one summary row per payment, attempts numbered by
     * window functions) gives exactly what the PHP rules give over the raw
     * attempt rows — on whichever driver runs the suite.
     */
    public function test_sql_summaries_match_the_php_rules_over_raw_attempts(): void
    {
        $this->septemberStory($this->shop);
        $since = CarbonImmutable::parse('2026-08-01');

        $fromSql = Tenant::run($this->shop, fn (): array => (new PaymentJourneys(\App\Domain\Analytics\Filters::none()))->since($since));
        $fromSql = array_values(array_filter($fromSql, static fn (array $j): bool => $j['source'] === PaymentJourneys::SOURCE_PAYPLUS));

        $raw = \Illuminate\Support\Facades\DB::table('activity_events as e')
            ->join('installment_payments as p', 'p.id', '=', 'e.payment_id')
            ->join('installment_plans as pl', 'pl.id', '=', 'p.plan_id')
            ->where('e.shop_id', $this->shop->id)
            ->whereIn('e.kind', PaymentJourneys::ATTEMPT_KINDS)
            ->orderBy('e.created_at')->orderBy('e.id')
            ->get(['e.payment_id', 'e.kind', 'e.created_at as at', 'e.details', 'p.amount', 'p.status as payment_status', 'pl.id as plan_id', 'pl.status as plan_status']);
        $cards = \Illuminate\Support\Facades\DB::table('activity_events')->where('shop_id', $this->shop->id)
            ->whereIn('kind', PaymentJourneys::CARD_KINDS)->orderBy('created_at')->get(['plan_id', 'kind', 'created_at']);

        $expected = [];
        foreach ($raw->groupBy('payment_id') as $paymentId => $attempts) {
            $rows = $attempts->map(static function ($a): object {
                $d = json_decode((string) $a->details, true) ?: [];

                return (object) [...(array) $a, 'message' => $d['error_message'] ?? null, 'code' => $d['error_code'] ?? null];
            })->all();
            $planCards = $cards->where('plan_id', $rows[0]->plan_id)->map(static fn ($c): array => ['kind' => $c->kind, 'at' => (string) $c->created_at])->values()->all();
            $expected[] = PaymentJourneys::build((int) $paymentId, $rows, $planCards);
        }

        $sort = static fn (array $a, array $b): int => $a['id'] <=> $b['id'];
        usort($fromSql, $sort);
        usort($expected, $sort);

        $this->assertCount(5, $fromSql, 'One journey per payment, not per attempt.');
        $this->assertEquals($expected, $fromSql);
    }

    public function test_the_journey_rules(): void
    {
        $row = static fn (string $kind, string $at, string $status = 'succeeded', string $plan = 'active'): object => (object) [
            'kind' => $kind, 'at' => $at, 'message' => 'סירוב. העסקה לא אושרה', 'code' => '1',
            'amount' => 10, 'payment_status' => $status, 'plan_id' => 1, 'plan_status' => $plan,
        ];

        $stopped = PaymentJourneys::build(1, [$row('charge_retry_scheduled', '2026-09-01 10:00', 'retry_scheduled', 'cancelled')]);
        $this->assertSame(PaymentJourneys::LOST, $stopped['outcome'], 'A cancelled plan stops the asking.');
        $this->assertSame(PaymentJourneys::LOST_STOPPED, $stopped['lost_reason']);
        $this->assertSame(DeclineReason::REFUSED, $stopped['reason']);

        $waiting = PaymentJourneys::build(2, [$row('charge_retry_scheduled', '2026-09-01 10:00', 'retry_scheduled')]);
        $this->assertSame(PaymentJourneys::UNDER_RECOVERY, $waiting['outcome']);
    }

    public function test_decline_text_buckets(): void
    {
        $this->assertSame(DeclineReason::STOLEN, DeclineReason::of('גנוב, החרם כרטיס'));
        $this->assertSame(DeclineReason::EXPIRED, DeclineReason::of('עסקה נדחתה: הכרטיס אינו בתוקף'));
        $this->assertSame(DeclineReason::BLOCKED, DeclineReason::of('כרטיס חסום'));
        $this->assertSame(DeclineReason::REFUSED, DeclineReason::of('סירוב. העסקה לא אושרה'));
        $this->assertSame(DeclineReason::TOKEN_MISSING, DeclineReason::of('this-token-not-exist'));
        $this->assertSame(DeclineReason::CALL_ISSUER, DeclineReason::of('התקשר לחברת האשראי'));
        $this->assertSame(DeclineReason::INSUFFICIENT_FUNDS, DeclineReason::of(null, 'insufficient_funds'));
        $this->assertSame(DeclineReason::OTHER, DeclineReason::of('משהו חדש לגמרי'));
        $this->assertSame(DeclineReason::OTHER, DeclineReason::of(''));
    }

    public function test_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'payments', 'recovery')
            ->assertOk()
            ->assertSee(__('analytics/payments_recovery.empty.title'));

        Cache::flush();
        $this->septemberStory($this->shop);

        Livewire::test(Analytics::class)->call('go', 'payments', 'recovery')
            ->assertOk()
            ->assertSee(__('analytics/payments_recovery.strategy.title'))
            ->assertSee(__('analytics/payments_recovery.strategy.backup_card'))
            ->assertSee(__('analytics/payments_failures.reason.blocked'))
            ->call('setGrain', 'recovery_trend', 'daily')
            ->assertOk();
    }
}
