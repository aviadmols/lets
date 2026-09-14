<?php

namespace Tests\Feature\Bulk;

use App\Domain\Bulk\BulkEditContext;
use App\Domain\Bulk\InvalidBulkEdit;
use App\Domain\Bulk\Operations\SetNextChargeDate;
use App\Domain\Bulk\Operations\ShiftNextChargeDate;
use App\Domain\Bulk\SubscriptionCriteria;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Moving many charge dates at once — the operation this screen was asked for.
 *
 * Two verbs with deliberately different shapes. SETTING a date collapses a group
 * onto one day, which is what "everyone who bills on the 3rd now bills on the
 * 10th" means. SHIFTING keeps the group spread out, which is what "push everybody
 * back a week" means — and collapsing those four thousand subscribers onto one
 * morning instead would be four thousand charges in an hour.
 *
 * The rules pinned here are the ones that cost money if they break: a terminal
 * subscription never gets a charge date, a date that is already due needs an
 * explicit acknowledgement, and a row already on the target value is skipped so
 * re-running is free.
 */
final class BulkDateOperationsTest extends TestCase
{
    use MakesBulkSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    private BulkEditContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop();
        Tenant::set($this->shop);
        $this->context = new BulkEditContext(runId: 77, shopId: (int) $this->shop->getKey(), actor: 'admin:9');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Setting one date ===

    public function test_it_moves_every_matched_subscription_to_one_date(): void
    {
        $third = $this->makePlan($this->shop, 'third', ['next_charge_at' => '2026-10-03 00:00:00']);
        $alsoThird = $this->makePlan($this->shop, 'also-third', ['next_charge_at' => '2026-10-03 09:15:00']);
        $fourth = $this->makePlan($this->shop, 'fourth', ['next_charge_at' => '2026-10-04 00:00:00']);

        $this->runSetDate('2026-10-10', ['next_charge_from' => '2026-10-03', 'next_charge_until' => '2026-10-03']);

        $this->assertSame('2026-10-10', $third->fresh()->next_charge_at->toDateString());
        $this->assertSame('2026-10-10', $alsoThird->fresh()->next_charge_at->toDateString());
        $this->assertSame('2026-10-04', $fourth->fresh()->next_charge_at->toDateString(), 'outside the filter, untouched');
    }

    /** A charge date is a DAY. Two subscribers must not end up on different times. */
    public function test_the_new_date_is_the_start_of_the_day(): void
    {
        $plan = $this->makePlan($this->shop, 'noon', ['next_charge_at' => '2026-10-03 14:37:00']);

        $this->runSetDate('2026-10-10');

        $this->assertSame('2026-10-10 00:00:00', $plan->fresh()->next_charge_at->toDateTimeString());
    }

    /** Both kinds. next_charge_at is the one clock the scheduler reads for either. */
    public function test_it_reschedules_an_instalment_plan_as_well_as_a_recurring_one(): void
    {
        $recurring = $this->makePlan($this->shop, 'recurring');
        $instalments = $this->makePlan($this->shop, 'instalments', ['plan_kind' => PlanKind::INSTALLMENTS->value]);

        $outcome = $this->runSetDate('2026-11-01');

        $this->assertSame(2, $outcome->changed);
        $this->assertSame('2026-11-01', $recurring->fresh()->next_charge_at->toDateString());
        $this->assertSame('2026-11-01', $instalments->fresh()->next_charge_at->toDateString());
    }

    /**
     * MONEY RULE: a finished subscription is never given a charge date.
     *
     * The same gate the detail page applies. Handing the scheduler a cancelled plan
     * with a future date would bill somebody who ended their subscription.
     */
    public function test_a_cancelled_or_completed_subscription_is_left_alone(): void
    {
        $cancelled = $this->makePlan($this->shop, 'cancelled', [
            'status' => PlanStatus::CANCELLED->value,
            'next_charge_at' => null,
        ]);
        $completed = $this->makePlan($this->shop, 'completed', [
            'status' => PlanStatus::COMPLETED->value,
            'next_charge_at' => null,
        ]);
        $active = $this->makePlan($this->shop, 'active');

        $outcome = $this->runSetDate('2026-11-01');

        $this->assertSame(1, $outcome->changed);
        $this->assertNull($cancelled->fresh()->next_charge_at);
        $this->assertNull($completed->fresh()->next_charge_at);
        $this->assertSame('2026-11-01', $active->fresh()->next_charge_at->toDateString());
    }

    /** A row already on the target is SKIPPED — which is what makes a re-run free. */
    public function test_a_subscription_already_on_the_date_is_skipped_not_rewritten(): void
    {
        $this->makePlan($this->shop, 'already', ['next_charge_at' => '2026-11-01 00:00:00']);
        $this->makePlan($this->shop, 'moving', ['next_charge_at' => '2026-10-03 00:00:00']);

        $outcome = $this->runSetDate('2026-11-01');

        $this->assertSame(1, $outcome->changed);
        $this->assertSame(1, $outcome->skipped);

        // The second pass has nothing left to do at all.
        $again = $this->runSetDate('2026-11-01');

        $this->assertSame(0, $again->changed);
        $this->assertSame(2, $again->skipped);
    }

    /**
     * Every changed row gets its OWN audit entry, naming the run.
     *
     * Not one summary row: the question a merchant asks three weeks later is "why
     * did THIS customer's date move", and they ask it on that customer's screen.
     */
    public function test_each_changed_subscription_gets_its_own_audit_row(): void
    {
        $plan = $this->makePlan($this->shop, 'audited', ['next_charge_at' => '2026-10-03 00:00:00']);
        $this->makePlan($this->shop, 'already', ['next_charge_at' => '2026-11-01 00:00:00']);

        $this->runSetDate('2026-11-01');

        $events = ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_EDITED)->get();

        $this->assertCount(1, $events, 'the skipped row writes no audit');

        $event = $events->first();
        $this->assertSame($plan->id, $event->plan_id);
        $this->assertSame('admin:9', $event->actor, 'the actor frozen on the run, not "system"');
        $this->assertSame(77, $event->details['bulk_edit_id']);
        $this->assertSame('2026-10-03', $event->details['changed']['next_charge_at']['from']);
        $this->assertSame('2026-11-01', $event->details['changed']['next_charge_at']['to']);
    }

    public function test_a_due_now_date_is_refused_without_the_acknowledgement(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new SetNextChargeDate)->normalise([
            SetNextChargeDate::PARAM_DATE => now()->toDateString(),
        ]);
    }

    public function test_a_due_now_date_is_allowed_once_acknowledged(): void
    {
        $params = (new SetNextChargeDate)->normalise([
            SetNextChargeDate::PARAM_DATE => now()->subDay()->toDateString(),
            SetNextChargeDate::PARAM_ALLOW_DUE_NOW => true,
        ]);

        $this->assertSame(now()->subDay()->toDateString(), $params[SetNextChargeDate::PARAM_DATE]);
        $this->assertTrue($params[SetNextChargeDate::PARAM_ALLOW_DUE_NOW]);
    }

    public function test_an_unreadable_date_is_refused(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new SetNextChargeDate)->normalise([SetNextChargeDate::PARAM_DATE => 'the third of never']);
    }

    // === Shifting by an interval ===

    /** The group stays spread out — each row keeps its own distance from the others. */
    public function test_a_shift_keeps_the_group_spread_out(): void
    {
        $third = $this->makePlan($this->shop, 'third', ['next_charge_at' => '2026-10-03 00:00:00']);
        $fifth = $this->makePlan($this->shop, 'fifth', ['next_charge_at' => '2026-10-05 00:00:00']);

        $outcome = $this->runShift(7, ShiftNextChargeDate::UNIT_DAYS);

        $this->assertSame(2, $outcome->changed);
        $this->assertSame('2026-10-10', $third->fresh()->next_charge_at->toDateString());
        $this->assertSame('2026-10-12', $fifth->fresh()->next_charge_at->toDateString());
    }

    /**
     * Months do not overflow — the 31st plus a month is the 28th of February.
     *
     * The same arithmetic BillingFrequency uses for an ordinary cycle, so a bulk
     * shift cannot put a subscriber on a date their own cadence would never produce.
     */
    public function test_a_month_shift_does_not_overflow_into_the_next_month(): void
    {
        $endOfJanuary = $this->makePlan($this->shop, 'jan31', ['next_charge_at' => '2027-01-31 00:00:00']);

        $this->runShift(1, ShiftNextChargeDate::UNIT_MONTHS);

        $this->assertSame('2027-02-28', $endOfJanuary->fresh()->next_charge_at->toDateString());
    }

    /** A stopped clock is not shifted — there is no "a week later" than nothing. */
    public function test_a_subscription_with_no_charge_date_is_not_given_one(): void
    {
        $stopped = $this->makePlan($this->shop, 'stopped', ['next_charge_at' => null]);

        $outcome = $this->runShift(7, ShiftNextChargeDate::UNIT_DAYS);

        $this->assertSame(0, $outcome->changed);
        $this->assertNull($stopped->fresh()->next_charge_at);
    }

    public function test_a_backwards_shift_is_refused_without_the_acknowledgement(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new ShiftNextChargeDate)->normalise([
            ShiftNextChargeDate::PARAM_AMOUNT => -7,
            ShiftNextChargeDate::PARAM_UNIT => ShiftNextChargeDate::UNIT_DAYS,
        ]);
    }

    public function test_a_backwards_shift_moves_dates_earlier_once_acknowledged(): void
    {
        $plan = $this->makePlan($this->shop, 'earlier', ['next_charge_at' => '2026-10-10 00:00:00']);

        $this->runShift(-3, ShiftNextChargeDate::UNIT_DAYS, acknowledge: true);

        $this->assertSame('2026-10-07', $plan->fresh()->next_charge_at->toDateString());
    }

    public function test_a_zero_shift_is_refused(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new ShiftNextChargeDate)->normalise([
            ShiftNextChargeDate::PARAM_AMOUNT => 0,
            ShiftNextChargeDate::PARAM_UNIT => ShiftNextChargeDate::UNIT_DAYS,
        ]);
    }

    /** A typo in a bulk shift is a subscriber billed in 2031. Bounded per unit. */
    public function test_an_absurd_shift_is_refused(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new ShiftNextChargeDate)->normalise([
            ShiftNextChargeDate::PARAM_AMOUNT => ShiftNextChargeDate::MAX_AMOUNT[ShiftNextChargeDate::UNIT_MONTHS] + 1,
            ShiftNextChargeDate::PARAM_UNIT => ShiftNextChargeDate::UNIT_MONTHS,
        ]);
    }

    /** The preview and the write share one arithmetic — they cannot disagree. */
    public function test_the_preview_shows_the_date_the_write_will_produce(): void
    {
        $plan = $this->makePlan($this->shop, 'preview', ['next_charge_at' => '2026-10-03 00:00:00']);

        $operation = new ShiftNextChargeDate;
        $params = $operation->normalise([
            ShiftNextChargeDate::PARAM_AMOUNT => 2,
            ShiftNextChargeDate::PARAM_UNIT => ShiftNextChargeDate::UNIT_WEEKS,
        ]);

        $preview = $operation->preview($plan, $params);

        $operation->apply(collect([$plan]), $params, $this->context);

        $this->assertSame($preview['after'], $plan->fresh()->next_charge_at->toDateString());
        $this->assertSame('2026-10-17', $preview['after']);
    }

    // === Helpers ===

    /** @param array<string, mixed> $criteria */
    private function runSetDate(string $date, array $criteria = [])
    {
        $operation = new SetNextChargeDate;
        $params = $operation->normalise([
            SetNextChargeDate::PARAM_DATE => $date,
            SetNextChargeDate::PARAM_ALLOW_DUE_NOW => Carbon::parse($date)->lte(now()),
        ]);

        $plans = $operation->eligible(SubscriptionCriteria::fromArray($criteria)->apply(), $params)
            ->orderBy('id')
            ->get();

        return $operation->apply($plans, $params, $this->context);
    }

    private function runShift(int $amount, string $unit, bool $acknowledge = false)
    {
        $operation = new ShiftNextChargeDate;
        $params = $operation->normalise([
            ShiftNextChargeDate::PARAM_AMOUNT => $amount,
            ShiftNextChargeDate::PARAM_UNIT => $unit,
            ShiftNextChargeDate::PARAM_ALLOW_DUE_NOW => $acknowledge,
        ]);

        $plans = $operation->eligible(SubscriptionCriteria::fromArray([])->apply(), $params)
            ->orderBy('id')
            ->get();

        return $operation->apply($plans, $params, $this->context);
    }
}
