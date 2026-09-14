<?php

namespace Tests\Feature\Bulk;

use App\Domain\Bulk\BulkEditContext;
use App\Domain\Bulk\InvalidBulkEdit;
use App\Domain\Bulk\Operations\ChangeBillingFrequency;
use App\Domain\Bulk\Operations\PauseSubscriptions;
use App\Domain\Bulk\Operations\ResumeSubscriptions;
use App\Domain\Bulk\SubscriptionCriteria;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-cadencing a book, and holding one.
 *
 * The frequency rules here are INHERITED from the single-subscription action
 * rather than re-decided, and that is the point of testing them twice: a bulk edit
 * must never be able to put a subscription into a state its own screen would
 * refuse to set. The one that matters most is that changing the cadence does NOT
 * move the next charge date — doing that to four thousand people is charging four
 * thousand people early.
 *
 * The lifecycle rules are about the state machine keeping its authority when the
 * work arrives a hundred rows at a time: a paused plan comes back through
 * SubscriptionLifecycleService (with its elapsed-date and held-cycle rules
 * intact), and a plan that moved under the run is skipped rather than forced.
 */
final class BulkFrequencyAndLifecycleTest extends TestCase
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
        $this->context = new BulkEditContext(runId: 12, shopId: (int) $this->shop->getKey(), actor: 'admin:4');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Frequency ===

    public function test_it_re_cadences_a_group_of_recurring_subscriptions(): void
    {
        $one = $this->makePlan($this->shop, 'one');
        $two = $this->makePlan($this->shop, 'two');

        $outcome = $this->runFrequency(2, BillingFrequency::MONTHLY->value);

        $this->assertSame(2, $outcome->changed);

        foreach ([$one, $two] as $plan) {
            $fresh = $plan->fresh();
            $this->assertSame(BillingFrequency::MONTHLY, $fresh->billing_frequency);
            $this->assertSame(2, $fresh->interval_count);
        }
    }

    /**
     * THE RULE THAT COSTS MONEY IF IT BREAKS.
     *
     * The cadence changes; the next charge date does not. The new interval governs
     * the cycle after the next one, because the orchestrator advances from the date
     * it actually charged on.
     */
    public function test_it_does_not_move_the_next_charge_date(): void
    {
        $plan = $this->makePlan($this->shop, 'due-29th', ['next_charge_at' => '2026-10-29 00:00:00']);

        $this->runFrequency(3, BillingFrequency::MONTHLY->value);

        $this->assertSame('2026-10-29', $plan->fresh()->next_charge_at->toDateString());
    }

    /** An instalment plan bills a fixed schedule — it has no cadence to re-negotiate. */
    public function test_an_instalment_plan_is_not_re_cadenced(): void
    {
        $instalments = $this->makePlan($this->shop, 'instalments', [
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'interval_count' => 1,
        ]);
        $recurring = $this->makePlan($this->shop, 'recurring');

        $outcome = $this->runFrequency(6, BillingFrequency::MONTHLY->value);

        $this->assertSame(1, $outcome->changed);
        $this->assertSame(1, $instalments->fresh()->interval_count, 'untouched');
        $this->assertSame(6, $recurring->fresh()->interval_count);
    }

    public function test_a_subscription_already_on_the_cadence_is_skipped(): void
    {
        $this->makePlan($this->shop, 'already'); // monthly, every 1
        $this->makePlan($this->shop, 'moving', ['interval_count' => 3]);

        $outcome = $this->runFrequency(1, BillingFrequency::MONTHLY->value);

        $this->assertSame(1, $outcome->changed);
        $this->assertSame(1, $outcome->skipped);
    }

    public function test_the_cadence_change_is_audited_with_the_old_and_new_values(): void
    {
        $plan = $this->makePlan($this->shop, 'audited', ['interval_count' => 1]);

        $this->runFrequency(2, BillingFrequency::MONTHLY->value);

        $event = ActivityEvent::query()
            ->where('kind', Timeline::KIND_PLAN_EDITED)
            ->where('plan_id', $plan->id)
            ->firstOrFail();

        $this->assertSame('1 monthly', $event->details['changed']['billing_frequency']['from']);
        $this->assertSame('2 monthly', $event->details['changed']['billing_frequency']['to']);
        $this->assertSame(12, $event->details['bulk_edit_id']);
    }

    /** Months and years only — the same pair the detail page offers. */
    public function test_a_cadence_the_detail_page_refuses_is_refused_here_too(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new ChangeBillingFrequency)->normalise([
            ChangeBillingFrequency::PARAM_INTERVAL => 1,
            ChangeBillingFrequency::PARAM_UNIT => BillingFrequency::WEEKLY->value,
        ]);
    }

    public function test_an_interval_past_the_ceiling_is_refused(): void
    {
        $this->expectException(InvalidBulkEdit::class);

        (new ChangeBillingFrequency)->normalise([
            ChangeBillingFrequency::PARAM_INTERVAL => ChangeBillingFrequency::MAX_INTERVAL + 1,
            ChangeBillingFrequency::PARAM_UNIT => BillingFrequency::MONTHLY->value,
        ]);
    }

    // === Pause / resume ===

    public function test_it_pauses_active_and_dunning_subscriptions(): void
    {
        $active = $this->makePlan($this->shop, 'active');
        $dunning = $this->makePlan($this->shop, 'dunning', ['status' => PlanStatus::AWAITING_PAYMENT->value]);
        $alreadyPaused = $this->makePlan($this->shop, 'paused', ['status' => PlanStatus::PAUSED->value]);

        $outcome = $this->runLifecycle(app(PauseSubscriptions::class));

        $this->assertSame(2, $outcome->changed);
        $this->assertSame(PlanStatus::PAUSED, $active->fresh()->status);
        $this->assertSame(PlanStatus::PAUSED, $dunning->fresh()->status);
        $this->assertSame(PlanStatus::PAUSED, $alreadyPaused->fresh()->status, 'not in the eligible set');
    }

    /** The hold keeps the billing day — it does not forgive it. */
    public function test_pausing_leaves_the_charge_date_where_it_is(): void
    {
        $plan = $this->makePlan($this->shop, 'held', ['next_charge_at' => '2026-10-03 00:00:00']);

        $this->runLifecycle(app(PauseSubscriptions::class));

        $this->assertSame('2026-10-03', $plan->fresh()->next_charge_at->toDateString());
    }

    /** Every move writes a state-machine audit row, per subscription. */
    public function test_each_pause_writes_its_own_state_change_naming_the_run(): void
    {
        $plan = $this->makePlan($this->shop, 'audited');

        $this->runLifecycle(app(PauseSubscriptions::class));

        $event = ActivityEvent::query()
            ->where('kind', Timeline::KIND_STATUS_CHANGED)
            ->where('plan_id', $plan->id)
            ->firstOrFail();

        $this->assertSame(PlanStatus::ACTIVE->value, $event->details['from']);
        $this->assertSame(PlanStatus::PAUSED->value, $event->details['to']);
        $this->assertSame(PauseSubscriptions::REASON_PREFIX.'12', $event->details['reason']);
    }

    /**
     * Resume goes through the lifecycle service, so its elapsed-date rule applies:
     * a date that went by while the plan was paused snaps to today, and the
     * scheduler bills ONE charge rather than every cycle that was missed.
     */
    public function test_resume_snaps_an_elapsed_date_to_today(): void
    {
        $plan = $this->makePlan($this->shop, 'stale', [
            'status' => PlanStatus::PAUSED->value,
            'next_charge_at' => now()->subMonths(3)->startOfDay(),
        ]);

        $this->runLifecycle(app(ResumeSubscriptions::class));

        $fresh = $plan->fresh();
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
        $this->assertSame(now()->startOfDay()->toDateString(), $fresh->next_charge_at->toDateString());
    }

    /**
     * …unless WE paused it for an unpaid cycle, in which case it keeps the date it
     * owes. Resuming must not read as forgiving last month's money.
     */
    public function test_resume_keeps_the_owed_date_on_a_plan_we_held(): void
    {
        $plan = $this->makePlan($this->shop, 'owes', [
            'status' => PlanStatus::PAUSED->value,
            'next_charge_at' => now()->subMonth()->startOfDay(),
            'payment_failed_at' => now()->subMonth(),
        ]);

        $owed = $plan->next_charge_at->toDateString();

        $this->runLifecycle(app(ResumeSubscriptions::class));

        $this->assertSame($owed, $plan->fresh()->next_charge_at->toDateString());
    }

    /**
     * A subscription that moved between the count and the chunk is SKIPPED.
     *
     * The run holds a model it read a moment ago; by the time it acts, a customer
     * may have cancelled. Losing that race must mean "left alone" — never a forced
     * transition, and never an exception that fails the other ninety-nine rows.
     */
    public function test_a_subscription_that_moved_under_the_run_is_skipped(): void
    {
        $plan = $this->makePlan($this->shop, 'races');

        // Read as the runner would, then cancelled underneath by somebody else.
        $held = SubscriptionCriteria::fromArray([])->apply()->get();
        $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

        $outcome = app(PauseSubscriptions::class)->apply($held, [], $this->context);

        $this->assertSame(0, $outcome->changed);
        $this->assertSame(1, $outcome->skipped);
        $this->assertSame(0, $outcome->failed);
        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status, 'not resurrected');
    }

    // === Helpers ===

    private function runFrequency(int $interval, string $unit)
    {
        $operation = new ChangeBillingFrequency;
        $params = $operation->normalise([
            ChangeBillingFrequency::PARAM_INTERVAL => $interval,
            ChangeBillingFrequency::PARAM_UNIT => $unit,
        ]);

        $plans = $operation->eligible(SubscriptionCriteria::fromArray([])->apply(), $params)
            ->orderBy('id')
            ->get();

        return $operation->apply($plans, $params, $this->context);
    }

    private function runLifecycle(object $operation)
    {
        $plans = $operation->eligible(SubscriptionCriteria::fromArray([])->apply(), [])
            ->orderBy('id')
            ->get();

        return $operation->apply($plans, [], $this->context);
    }
}
