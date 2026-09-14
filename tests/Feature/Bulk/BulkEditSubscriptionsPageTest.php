<?php

namespace Tests\Feature\Bulk;

use App\Domain\Bulk\BulkEditRunner;
use App\Domain\Bulk\Jobs\RunBulkSubscriptionEditJob;
use App\Domain\Bulk\Models\BulkSubscriptionEdit;
use App\Domain\Bulk\Operations\ChangeBillingFrequency;
use App\Domain\Bulk\Operations\SetNextChargeDate;
use App\Domain\Bulk\Operations\ShiftNextChargeDate;
use App\Filament\Pages\BulkEditSubscriptions;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen, and the walls that stop it running the wrong edit.
 *
 * Its whole job is to make the consequence visible BEFORE it happens, so what is
 * tested here is mostly refusal: you cannot apply without previewing, a preview
 * dies the moment you change the filter under it, a large edit needs its count
 * typed, and a change that would charge cards immediately needs saying so out
 * loud. The happy path is one test; the walls are the rest.
 */
final class BulkEditSubscriptionsPageTest extends TestCase
{
    use MakesBulkSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('bulk-page.example.com');
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_screen_renders_with_no_preview_and_no_run(): void
    {
        Livewire::test(BulkEditSubscriptions::class)
            ->assertOk()
            ->assertSet('plan', null)
            ->assertSet('runId', null);
    }

    /** The preview counts what matches, and what the verb may actually touch. */
    public function test_the_preview_separates_what_matches_from_what_can_change(): void
    {
        $this->makePlan($this->shop, 'active-one');
        $this->makePlan($this->shop, 'active-two');
        $this->makePlan($this->shop, 'cancelled', ['status' => PlanStatus::CANCELLED->value]);

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview');

        $plan = $component->get('plan');

        $this->assertSame(3, $plan['matched']);
        $this->assertSame(2, $plan['eligible'], 'the cancelled one cannot take a charge date');
        $this->assertSame(1, $plan['ineligible']);
        $this->assertCount(2, $plan['sample']);
        $this->assertTrue($plan['unfiltered'], 'no filters were set, and the screen says so');
    }

    /** The sample shows real rows as before → after, in the order the run will reach them. */
    public function test_the_preview_shows_the_change_row_by_row(): void
    {
        $this->makePlan($this->shop, 'dana', ['next_charge_at' => '2026-10-03 00:00:00']);

        $plan = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->get('plan');

        $this->assertSame('dana', $plan['sample'][0]['customer']);
        $this->assertSame('2026-10-03', $plan['sample'][0]['before']);
        $this->assertSame('2026-11-01', $plan['sample'][0]['after']);
    }

    /**
     * THE MOST IMPORTANT WALL ON THE SCREEN.
     *
     * Preview twelve subscriptions, widen the filter, and the Apply button must not
     * still be holding "12". Any change to the target or the change throws the
     * preview away, so a confirmation always belongs to the numbers on screen.
     */
    public function test_changing_the_filter_throws_the_preview_away(): void
    {
        $this->makePlan($this->shop, 'recurring');
        $this->makePlan($this->shop, 'instalments', ['plan_kind' => PlanKind::INSTALLMENTS->value]);

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->set('planKind', PlanKind::RECURRING->value)
            ->call('preview');

        $this->assertSame(1, $component->get('plan')['eligible']);

        $component->set('planKind', '');

        $this->assertNull($component->get('plan'), 'the preview did not survive the filter change');
    }

    public function test_changing_the_verb_throws_the_preview_away(): void
    {
        $this->makePlan($this->shop, 'one');

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->assertSet('plan.eligible', 1);

        $component->set('operation', ChangeBillingFrequency::KEY);

        $this->assertNull($component->get('plan'));
    }

    public function test_applying_without_a_preview_is_refused(): void
    {
        $this->makePlan($this->shop, 'one');

        Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('apply')
            ->assertNotified(__('subscriptions.bulk.error.preview_first'));

        $this->assertSame(0, BulkSubscriptionEdit::query()->count());
        Queue::assertNothingPushed();
    }

    /** A small edit runs on a normal confirmation. */
    public function test_a_small_edit_queues_a_run(): void
    {
        $this->makePlan($this->shop, 'one');
        $this->makePlan($this->shop, 'two');

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->call('apply');

        $run = BulkSubscriptionEdit::query()->firstOrFail();

        $this->assertSame(SetNextChargeDate::KEY, $run->operation);
        $this->assertSame(2, $run->eligible_count);
        $this->assertSame((int) $run->getKey(), $component->get('runId'));
        $this->assertNull($component->get('plan'), 'the preview is spent');

        Queue::assertPushed(RunBulkSubscriptionEditJob::class);
    }

    /**
     * Above the threshold the count has to be TYPED — the cheapest wall between a
     * mis-set filter and four thousand people billed on the wrong day.
     */
    public function test_a_large_edit_refuses_a_wrong_typed_count(): void
    {
        $count = BulkEditSubscriptions::TYPED_CONFIRM_THRESHOLD + 5;
        $this->makePlans($this->shop, $count);

        Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->assertSet('plan.eligible', $count)
            ->set('confirmCount', (string) ($count - 1))
            ->call('apply')
            ->assertNotified(__('subscriptions.bulk.error.confirm_count'));

        $this->assertSame(0, BulkSubscriptionEdit::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_large_edit_runs_once_the_count_is_typed(): void
    {
        $count = BulkEditSubscriptions::TYPED_CONFIRM_THRESHOLD + 5;
        $this->makePlans($this->shop, $count);

        Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->set('confirmCount', (string) $count)
            ->call('apply');

        $this->assertSame(1, BulkSubscriptionEdit::query()->count());
        $this->assertSame($count, (int) BulkSubscriptionEdit::query()->firstOrFail()->eligible_count);

        Queue::assertPushed(RunBulkSubscriptionEditJob::class);
    }

    /**
     * The confirmation is judged against a FRESH count, not the one on screen.
     *
     * Between previewing and confirming, subscriptions can arrive into the filter
     * or leave it. When that moves the count, the honest answer is to show the new
     * number and ask again — never to run against a figure nobody confirmed.
     */
    public function test_the_typed_count_is_checked_against_a_recount(): void
    {
        $count = BulkEditSubscriptions::TYPED_CONFIRM_THRESHOLD + 5;
        $this->makePlans($this->shop, $count);

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->set('confirmCount', (string) $count);

        // One more subscriber arrives into the filter before the click lands.
        $this->makePlan($this->shop, 'latecomer');

        $component->call('apply')->assertNotified(__('subscriptions.bulk.error.confirm_count'));

        $this->assertSame(0, BulkSubscriptionEdit::query()->count());
        $this->assertSame($count + 1, $component->get('plan')['eligible'], 'and the new number is on screen');
    }

    /** A date that is already due needs the acknowledgement, on the screen and in the domain. */
    public function test_a_due_now_date_is_refused_until_acknowledged(): void
    {
        $this->makePlan($this->shop, 'one');

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', now()->toDateString())
            ->call('preview')
            ->assertNotified(__('subscriptions.bulk.error.date_due_now'));

        $this->assertNull($component->get('plan'));
        $this->assertTrue($component->instance()->dueNowWarning(), 'and the screen is showing the warning');

        $component->set('allowDueNow', true)->call('preview');

        $this->assertSame(1, $component->get('plan')['eligible']);
    }

    public function test_a_backwards_shift_is_refused_until_acknowledged(): void
    {
        $this->makePlan($this->shop, 'one');

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', ShiftNextChargeDate::KEY)
            ->set('shiftAmount', '-7')
            ->set('shiftUnit', ShiftNextChargeDate::UNIT_DAYS)
            ->call('preview')
            ->assertNotified(__('subscriptions.bulk.error.shift_backwards'));

        $this->assertTrue($component->instance()->dueNowWarning());

        $component->set('allowDueNow', true)->call('preview');

        $this->assertSame(1, $component->get('plan')['eligible']);
    }

    /** A verb with nothing to act on is a refusal, not a run that does nothing. */
    public function test_a_target_the_verb_cannot_touch_is_refused(): void
    {
        // Instalment plans only, and the verb is recurring-only.
        $this->makePlan($this->shop, 'instalments', ['plan_kind' => PlanKind::INSTALLMENTS->value]);

        Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', ChangeBillingFrequency::KEY)
            ->set('freqInterval', '2')
            ->set('freqUnit', BillingFrequency::MONTHLY->value)
            ->call('preview')
            ->assertSet('plan.eligible', 0)
            ->call('apply')
            ->assertNotified(__('subscriptions.bulk.error.nothing_matched'));

        $this->assertSame(0, BulkSubscriptionEdit::query()->count());
    }

    /** The run's progress is read off the row, and polling stops when it is over. */
    public function test_the_screen_reports_a_finished_run(): void
    {
        $this->makePlan($this->shop, 'one');

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->call('apply');

        $runId = (int) $component->get('runId');
        app(BulkEditRunner::class)->advance($runId);

        $component->call('refreshRun');

        $this->assertSame(BulkSubscriptionEdit::STATUS_COMPLETED, $component->get('run')['status']);
        $this->assertSame(1, $component->get('run')['changed']);
        $this->assertTrue($component->instance()->runIsFinished());
    }

    public function test_a_merchant_can_stop_a_run(): void
    {
        $this->makePlan($this->shop, 'one');

        $component = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->call('apply')
            ->call('stopRun');

        $this->assertSame(BulkSubscriptionEdit::STATUS_CANCELLED, $component->get('run')['status']);
    }

    /** The filters the list screen hands over are adopted, and re-validated. */
    public function test_it_seeds_its_filters_from_the_lists_bulk_edit_link(): void
    {
        $component = Livewire::withQueryParams([
            'kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'frequency' => BillingFrequency::MONTHLY->value,
            'product' => self::PRODUCT_COFFEE,
            'from' => '2026-10-01',
            'until' => '2026-10-31',
            // Junk in the URL must not become a filter.
            'kind_extra' => 'ignored',
        ])->test(BulkEditSubscriptions::class);

        $component
            ->assertSet('planKind', PlanKind::RECURRING->value)
            ->assertSet('statuses', [PlanStatus::ACTIVE->value])
            ->assertSet('frequency', BillingFrequency::MONTHLY->value)
            ->assertSet('productId', self::PRODUCT_COFFEE)
            ->assertSet('chargeFrom', '2026-10-01')
            ->assertSet('chargeUntil', '2026-10-31');
    }

    /** A hand-edited URL cannot introduce a filter value the engine does not know. */
    public function test_a_tampered_seed_falls_out_of_the_target(): void
    {
        $this->makePlan($this->shop, 'one');

        $component = Livewire::withQueryParams(['kind' => 'not-a-kind'])
            ->test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview');

        // The unknown kind was dropped rather than queried, so the count is honest
        // rather than a silent zero.
        $this->assertSame(1, $component->get('plan')['eligible']);
    }

    /** The list's button carries the filters the merchant already set. */
    public function test_the_list_screens_link_carries_the_active_filters(): void
    {
        $this->makePlan($this->shop, 'one');

        $list = Livewire::test(ListSubscriptions::class)
            ->filterTable('billing_frequency', BillingFrequency::YEARLY->value)
            ->filterTable('plan_kind', PlanKind::RECURRING->value)
            ->instance();

        $url = collect($list->getCachedHeaderActions())
            ->first(fn (object $action): bool => $action->getName() === 'bulkEdit')
            ->getUrl();

        $this->assertStringContainsString('frequency='.BillingFrequency::YEARLY->value, $url);
        $this->assertStringContainsString('kind='.PlanKind::RECURRING->value, $url);
    }

    /** RELEASE BLOCKER: the screen's count only ever sees its own shop. */
    public function test_the_preview_never_counts_another_shops_subscriptions(): void
    {
        $this->makePlan($this->shop, 'mine');

        $other = $this->makeShop('other-page.example.com');
        Tenant::set($other);
        $this->makePlans($other, 40);
        Tenant::set($this->shop);

        $plan = Livewire::test(BulkEditSubscriptions::class)
            ->set('operation', SetNextChargeDate::KEY)
            ->set('date', '2026-11-01')
            ->call('preview')
            ->get('plan');

        $this->assertSame(1, $plan['matched']);
        $this->assertSame(1, $plan['eligible']);
    }
}
