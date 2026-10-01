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
