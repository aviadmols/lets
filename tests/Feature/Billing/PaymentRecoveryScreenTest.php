<?php

namespace Tests\Feature\Billing;

use App\Domain\Installments\Jobs\RunTokenRecoveryJob;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Domain\Installments\Models\TokenRecoveryRun;
use App\Domain\Installments\TokenRecoveryRunner;
use App\Filament\Pages\PaymentRecovery;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FAILED CHARGES — the money that did not arrive, and who has stopped being asked.
 *
 * The three groups are the whole feature, so they are what these tests pin. The
 * one that matters most is the middle one: a subscription whose attempts ran out is
 * HELD (paused, with `payment_failed_at` stamped and the debt still on its original
 * date), not cancelled. If that group ever quietly starts collecting cancelled
 * plans — or if the cancelled group starts collecting held ones — a merchant stops
 * chasing customers they could still keep, which is the exact failure this screen
 * was asked for to prevent.
 */
final class PaymentRecoveryScreenTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'recovery.example.com',
            'name' => 'Recovery',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_screen_renders_on_the_retrying_group(): void
    {
        Livewire::test(PaymentRecovery::class)
            ->assertOk()
            ->assertSet('activeTab', PaymentRecovery::TAB_RETRYING);
    }

    // === The three groups ===

    /** Still being asked: the newest slot is waiting out its backoff. */
    public function test_the_retrying_group_holds_the_plans_still_being_asked(): void
    {
        $retrying = $this->plan('retrying', PlanStatus::AWAITING_PAYMENT);
        $this->payment($retrying, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        $healthy = $this->plan('healthy', PlanStatus::ACTIVE);
        $this->payment($healthy, 1, PaymentStatus::SUCCEEDED);

        Livewire::test(PaymentRecovery::class)
            ->assertCanSeeTableRecords([$retrying])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    /**
     * A plan that failed ONCE long ago and has billed cleanly since is not
     * failing. Only the LATEST slot says what is happening — the same law the
     * subscriptions list learned, now expressed as a relation.
     */
    public function test_a_plan_that_recovered_is_not_in_any_group(): void
    {
        $recovered = $this->plan('recovered', PlanStatus::ACTIVE);
        $this->payment($recovered, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->subMonth());
        $this->payment($recovered, 2, PaymentStatus::SUCCEEDED);

        foreach (PaymentRecovery::TABS as $tab) {
            $this->assertSame(
                0,
                PaymentRecovery::scopeFor($tab, InstallmentPlan::query())->count(),
                'a recovered plan appeared in: '.$tab,
            );
        }
    }

    /**
     * THE MIDDLE GROUP. The attempts ran out and the engine HELD the plan:
     * paused, `payment_failed_at` stamped, and the debt still on its own date.
     */
    public function test_the_stopped_group_holds_the_plans_we_gave_up_asking(): void
    {
        $held = $this->plan('held', PlanStatus::PAUSED, failedAt: now()->subDays(3));
        $this->payment($held, 1, PaymentStatus::FAILED);

        // A plan the CUSTOMER paused is not here: no hold stamp, nothing owed.
        $customerPaused = $this->plan('customer-paused', PlanStatus::PAUSED);

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->assertCanSeeTableRecords([$held])
            ->assertCanNotSeeTableRecords([$customerPaused]);
    }

    /**
     * The debt is NOT forgiven and the date is NOT moved. This is what the middle
     * group's copy promises the merchant, so it is asserted rather than described.
     */
    public function test_a_held_plan_keeps_the_date_it_owes(): void
    {
        $owed = now()->subDays(5)->startOfDay();

        $held = $this->plan('owes', PlanStatus::PAUSED, failedAt: now()->subDay());
        $held->forceFill(['next_charge_at' => $owed])->save();
        $this->payment($held, 1, PaymentStatus::FAILED);

        $this->assertTrue(
            PaymentRecovery::scopeFor(PaymentRecovery::TAB_STOPPED, InstallmentPlan::query())
                ->whereKey($held->getKey())
                ->exists(),
        );

        $this->assertSame($owed->toDateString(), $held->fresh()->next_charge_at->toDateString());
    }

    /**
     * Cancelled BECAUSE OF THE CARD — a cancellation that happened while the hold
     * stamp was still on the plan. A customer who simply left has no stamp, and
     * must not be counted as lost to a card.
     */
    public function test_the_cancelled_group_separates_a_card_loss_from_a_customer_leaving(): void
    {
        $lostToCard = $this->plan('lost-to-card', PlanStatus::CANCELLED, failedAt: now()->subWeek());
        $this->payment($lostToCard, 1, PaymentStatus::FAILED);

        $justLeft = $this->plan('just-left', PlanStatus::CANCELLED);

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_CANCELLED)
            ->assertCanSeeTableRecords([$lostToCard])
            ->assertCanNotSeeTableRecords([$justLeft]);
    }

    /** The three groups never claim the same plan twice. */
    public function test_the_groups_do_not_overlap(): void
    {
        $retrying = $this->plan('r', PlanStatus::AWAITING_PAYMENT);
        $this->payment($retrying, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        $held = $this->plan('h', PlanStatus::PAUSED, failedAt: now());
        $this->payment($held, 1, PaymentStatus::FAILED);

        $cancelled = $this->plan('c', PlanStatus::CANCELLED, failedAt: now());
        $this->payment($cancelled, 1, PaymentStatus::FAILED);

        $seen = [];

        foreach (PaymentRecovery::TABS as $tab) {
            foreach (PaymentRecovery::scopeFor($tab, InstallmentPlan::query())->pluck('id') as $id) {
                $already = $seen[(int) $id] ?? null;

                $this->assertNull(
                    $already,
                    "plan {$id} is in both '{$already}' and '{$tab}'",
                );

                $seen[(int) $id] = $tab;
            }
        }

        $this->assertCount(3, $seen);
    }

    // === What the row says ===

    /** The next attempt is the answer this screen exists to give. */
    public function test_the_row_shows_the_next_attempt_and_the_attempt_count(): void
    {
        $at = now()->addDay()->startOfHour();

        $plan = $this->plan('next-attempt', PlanStatus::AWAITING_PAYMENT);
        $this->payment($plan, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: $at, attempts: 3);

        MerchantBillingSettings::current()->forceFill(['max_charge_attempts' => 7])->save();

        Livewire::test(PaymentRecovery::class)
            ->assertSee($at->format('d M Y H:i'))
            ->assertSee('3 / 7');
    }

    /** The gateway's own words, because that is what a merchant repeats on the phone. */
    public function test_the_row_shows_why_it_failed(): void
    {
        $plan = $this->plan('reason', PlanStatus::AWAITING_PAYMENT);
        $this->payment($plan, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());
        $plan->latestPayment->forceFill(['failure_message' => 'Insufficient funds'])->save();

        Livewire::test(PaymentRecovery::class)->assertSee('Insufficient funds');
    }

    /** A plan with no usable card is a different problem from a decline. */
    public function test_a_plan_with_no_usable_card_says_so(): void
    {
        $plan = $this->plan('no-card', PlanStatus::AWAITING_PAYMENT, withCard: false);
        $this->payment($plan, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        Livewire::test(PaymentRecovery::class)->assertSee(__('recovery.card.none'));
    }

    /**
     * A MIGRATED member who arrived already in arrears.
     *
     * The CSV importer maps a source status of past_due / unpaid / dunning /
     * declined straight onto `failed` (SubscriptionCsvSchema::STATUS_MAP), so these
     * plans have no charge slot, no retry stamp and no charge date — our engine
     * never touched them. They are exactly the people nobody is charging, so
     * requiring the engine's own stamp would hide them from the one screen that
     * exists to surface them.
     */
    public function test_an_imported_member_in_arrears_is_in_the_stopped_group(): void
    {
        // Exactly the production shape: status failed, no stamp, no date, no slots.
        $imported = $this->plan('imported', PlanStatus::FAILED);
        $imported->forceFill(['next_charge_at' => null, 'import_key' => 'legacy-1001'])->save();

        $this->assertTrue(
            PaymentRecovery::scopeFor(PaymentRecovery::TAB_STOPPED, InstallmentPlan::query())
                ->whereKey($imported->getKey())
                ->exists(),
            'a migrated member in arrears must be visible',
        );

        // And the row says WHY without inventing a decline that never happened.
        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->assertSee(__('recovery.reason.imported_unpaid'))
            ->assertDontSee(__('recovery.reason.unknown'));
    }

    /** A held row cannot show a retry date, so it says what WOULD make it charge. */
    public function test_a_held_row_says_it_is_waiting_on_the_card(): void
    {
        $held = $this->plan('waiting', PlanStatus::PAUSED, failedAt: now());
        $this->payment($held, 1, PaymentStatus::FAILED);

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->assertSee(__('recovery.next.on_card_update'));
    }

    // === The headline ===

    /** Money not collected, summed from the FAILING slots rather than the prices. */
    public function test_the_headline_sums_what_the_failing_cycles_owe(): void
    {
        $a = $this->plan('a', PlanStatus::AWAITING_PAYMENT);
        $this->payment($a, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay(), amount: 39.0);

        // A cycle re-priced by an intro window owes what it TRIED to take, not the
        // plan's steady-state price.
        $b = $this->plan('b', PlanStatus::AWAITING_PAYMENT);
        $b->forceFill(['installment_amount' => 119.0])->save();
        $this->payment($b, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay(), amount: 89.0);

        $component = Livewire::test(PaymentRecovery::class);

        $this->assertSame(2, $component->instance()->countFor(PaymentRecovery::TAB_RETRYING));
        $this->assertStringContainsString('128', $component->instance()->moneyAtRisk());
    }

    /** The sidebar badge counts the RECOVERABLE work, not the history. */
    public function test_the_sidebar_badge_counts_retrying_and_held_but_not_cancelled(): void
    {
        $retrying = $this->plan('badge-r', PlanStatus::AWAITING_PAYMENT);
        $this->payment($retrying, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        $held = $this->plan('badge-h', PlanStatus::PAUSED, failedAt: now());
        $this->payment($held, 1, PaymentStatus::FAILED);

        $cancelled = $this->plan('badge-c', PlanStatus::CANCELLED, failedAt: now());
        $this->payment($cancelled, 1, PaymentStatus::FAILED);

        $this->assertSame('2', PaymentRecovery::getNavigationBadge());
    }

    public function test_the_badge_is_absent_when_there_is_nothing_to_chase(): void
    {
        $this->plan('fine', PlanStatus::ACTIVE);

        $this->assertNull(PaymentRecovery::getNavigationBadge());
    }

    // === Actions ===

    /**
     * The bulk lever: one email per selected customer, and the outcome COUNTED.
     * "40 links sent" when eleven were skipped is what stops a merchant chasing
     * the other twenty-nine.
     */
    public function test_sending_card_update_links_skips_and_counts_what_it_cannot_send(): void
    {
        $withEmail = $this->plan('with-email', PlanStatus::PAUSED, failedAt: now());
        $this->payment($withEmail, 1, PaymentStatus::FAILED);

        $noEmail = $this->plan('no-email', PlanStatus::PAUSED, failedAt: now());
        $noEmail->forceFill(['customer_email' => null])->save();
        $this->payment($noEmail, 1, PaymentStatus::FAILED);

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->callTableBulkAction('sendCardUpdateLinks', [$withEmail, $noEmail])
            ->assertHasNoTableBulkActionErrors();

        // The one without an address minted NOTHING — an unusable plan must not
        // leave a live credential behind.
        $this->assertSame(
            0,
            CardUpdateLink::query()->where('plan_id', $noEmail->getKey())->count(),
        );
    }

    /**
     * Ask PayPlus what card it holds, for a whole selection.
     *
     * The per-subscription button only appears when the plan is provably in that
     * situation, so a migrated book's broken tokens surface one failed cycle at a
     * time. This is the same ImportedTokenRecovery run across a selection — and it
     * must still CHARGE NOTHING, which is what the gateway fake proves.
     *
     * The work happens on a worker (here, the sync queue driver runs it inline),
     * and every answer is committed to the run row as it arrives.
     */
    public function test_finding_saved_cards_in_bulk_charges_nothing_and_reports_each_wall(): void
    {
        $charged = 0;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($charged) implements PayPlusGatewayInterface
        {
            public function __construct(private int &$charged) {}

            public function chargeWithReference($method, float $amount, string $key, array $meta = []): GatewayResult
            {
                $this->charged++;

                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $uid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            // PayPlus holds nothing for these members — the "not found" wall, which
            // must be reported rather than silently counted as a failure.
            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success'], 'data' => []]);
            }
        });

        $a = $this->plan('probe-a', PlanStatus::AWAITING_PAYMENT);
        $this->payment($a, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        // No card record at all — skipped before a call is spent on it.
        $b = $this->plan('probe-b', PlanStatus::AWAITING_PAYMENT, withCard: false);
        $this->payment($b, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        Livewire::test(PaymentRecovery::class)
            ->callTableBulkAction('recoverTokens', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(0, $charged, 'finding a saved card must never move money');

        // The pass is a row, and it reached both members. The one with no card
        // record is skipped before a call is spent on it.
        $run = TokenRecoveryRun::query()->latest('id')->firstOrFail();

        $this->assertSame(TokenRecoveryRun::MODE_RECOVER, $run->mode);
        $this->assertSame(TokenRecoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->total);
        $this->assertSame(2, $run->processed);
        $this->assertSame(1, $run->skipped);
        $this->assertSame(0, $run->charges_queued, 'find-cards mode must queue no charge');

        PayPlusGatewayFactory::clearFake();
    }

    /**
     * THE BUG THIS SCREEN SHIPPED WITH: a hundred members was over a thousand
     * round trips to PayPlus, attempted inside the Livewire request. It died at the
     * proxy every time — and the writes had already landed, so cards HAD been
     * re-pointed and the only record of which ones died with the response.
     *
     * The click must now do three cheap things and return: bound the selection,
     * write a run row, dispatch a job. Nothing may reach PayPlus in the request.
     */
    public function test_the_click_only_queues_a_pass_and_never_calls_payplus_in_the_request(): void
    {
        Queue::fake();

        $plans = [];

        for ($i = 0; $i < 25; $i++) {
            $plan = $this->plan('bulk-'.$i, PlanStatus::PAUSED, failedAt: now());
            $this->payment($plan, 1, PaymentStatus::FAILED);
            $plans[] = $plan;
        }

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->callTableBulkAction('recoverTokens', $plans)
            ->assertHasNoTableBulkActionErrors();

        Queue::assertPushed(RunTokenRecoveryJob::class, 1);

        $run = TokenRecoveryRun::query()->latest('id')->firstOrFail();

        $this->assertSame(25, $run->total);
        $this->assertSame(TokenRecoveryRun::STATUS_QUEUED, $run->status);

        // THE ASSERTION THAT MATTERS. Not one of the twenty-five was touched while
        // the merchant waited: every lookup, and therefore every chance of the
        // request timing out, is on the other side of the queue.
        $this->assertSame(0, $run->processed);
        $this->assertSame(0, $run->cursor);
    }

    /**
     * A killed worker costs ONE member, not the run.
     *
     * The cursor and the counters are committed together after each member, so a
     * pass that dies at member three resumes at member three — it does not start
     * over (re-asking PayPlus about everybody) and it does not skip ahead.
     */
    public function test_a_pass_resumes_from_where_it_committed(): void
    {
        Queue::fake();

        $plans = [];

        for ($i = 0; $i < 5; $i++) {
            $plan = $this->plan('resume-'.$i, PlanStatus::PAUSED, failedAt: now(), withCard: false);
            $this->payment($plan, 1, PaymentStatus::FAILED);
            $plans[] = $plan;
        }

        $run = app(TokenRecoveryRunner::class)->start(
            $this->shop,
            array_map(fn (InstallmentPlan $p): int => (int) $p->getKey(), $plans),
            TokenRecoveryRun::MODE_RECOVER,
        );

        $runner = app(TokenRecoveryRunner::class);

        // A worker that only got through two before it died.
        $finished = $runner->advance((int) $run->getKey(), maxMembers: 2);

        $this->assertFalse($finished, 'a partial pass must ask to be continued');
        $this->assertSame(2, $run->refresh()->processed);
        $this->assertSame(2, $run->cursor);

        // Its replacement picks up at three, not at one.
        $runner->advance((int) $run->getKey());

        $run->refresh();
        $this->assertSame(5, $run->processed, 'every member is reached exactly once');
        $this->assertSame(TokenRecoveryRun::STATUS_COMPLETED, $run->status);
    }

    /**
     * Stop means stop, and it keeps what it found.
     *
     * A merchant who starts a hundred and changes their mind must not have to wait
     * for the run to end on its own — and the members already asked about keep
     * their answers, with the report saying plainly how many were never reached.
     */
    public function test_a_stopped_pass_keeps_its_results_and_admits_who_was_missed(): void
    {
        Queue::fake();

        $plans = [];

        for ($i = 0; $i < 6; $i++) {
            $plan = $this->plan('stop-'.$i, PlanStatus::PAUSED, failedAt: now(), withCard: false);
            $this->payment($plan, 1, PaymentStatus::FAILED);
            $plans[] = $plan;
        }

        $runner = app(TokenRecoveryRunner::class);
        $run = $runner->start(
            $this->shop,
            array_map(fn (InstallmentPlan $p): int => (int) $p->getKey(), $plans),
            TokenRecoveryRun::MODE_RECOVER,
        );

        $runner->advance((int) $run->getKey(), maxMembers: 2);
        $runner->cancel((int) $run->getKey());

        // A worker that was already mid-flight must not resurrect it.
        $this->assertTrue($runner->advance((int) $run->getKey()));

        $run->refresh();
        $this->assertSame(TokenRecoveryRun::STATUS_CANCELLED, $run->status);
        $this->assertSame(2, $run->processed, 'what was found is kept');
        $this->assertSame(4, $run->unreached(), 'and the four nobody asked about are admitted');
    }

    /** One pass at a time: two overlapping runs would ask PayPlus everything twice. */
    public function test_a_second_pass_is_refused_while_one_is_running(): void
    {
        Queue::fake();

        $plan = $this->plan('busy', PlanStatus::PAUSED, failedAt: now());
        $this->payment($plan, 1, PaymentStatus::FAILED);

        app(TokenRecoveryRunner::class)->start(
            $this->shop,
            [(int) $plan->getKey()],
            TokenRecoveryRun::MODE_RECOVER,
        );

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->callTableBulkAction('recoverTokens', [$plan])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(1, TokenRecoveryRun::query()->count(), 'the second click must not open a second pass');
    }

    /**
     * The merchant can SEE the pass — which is the whole point of moving it to a
     * worker rather than leaving it in a request that dies silently.
     */
    public function test_the_screen_shows_a_running_pass_and_then_its_report(): void
    {
        Queue::fake();

        $plan = $this->plan('visible', PlanStatus::PAUSED, failedAt: now(), withCard: false);
        $this->payment($plan, 1, PaymentStatus::FAILED);

        $runner = app(TokenRecoveryRunner::class);
        $run = $runner->start($this->shop, [(int) $plan->getKey()], TokenRecoveryRun::MODE_RECOVER);

        Livewire::test(PaymentRecovery::class)
            ->assertSee(__('recovery.run.title'))
            ->assertSee(__('recovery.run.stop'));

        $runner->advance((int) $run->getKey());

        Livewire::test(PaymentRecovery::class)
            ->assertDontSee(__('recovery.run.title'))
            ->assertSee(__('recovery.run.report_title'));
    }

    /**
     * A CARD ALREADY REPLACED IS STILL CHARGED — the bug a live run exposed.
     *
     * The merchant found new cards for four members in one pass, then pressed
     * "find and charge" in the next. That pass went looking for a SECOND
     * replacement, found none, and read "nothing found" as "no card to charge" —
     * refusing to bill the very card it had attached a minute earlier. Four cards
     * fixed, nobody charged, no money moved.
     *
     * The old decline describes a card that is no longer attached, so it is not
     * evidence about the one that is.
     */
    public function test_a_card_replaced_since_the_decline_is_charged_without_another_lookup(): void
    {
        Queue::fake();

        $plan = $this->plan('replaced', PlanStatus::PAUSED, failedAt: now());
        $plan->paymentMethod->forceFill(['payplus_customer_uid' => 'cust-x'])->save();
        $this->payment($plan, 1, PaymentStatus::FAILED, failureMessage: 'כרטיס חסום');

        // A previous pass re-pointed the card AFTER that decline.
        $this->travel(5)->minutes();
        $plan->paymentMethod->forceFill(['payplus_card_token_uid' => 'tok-brand-new'])->save();

        $run = app(TokenRecoveryRunner::class)->start(
            $this->shop,
            [(int) $plan->getKey()],
            TokenRecoveryRun::MODE_RECOVER_AND_CHARGE,
        );

        app(TokenRecoveryRunner::class)->advance((int) $run->getKey());

        Queue::assertPushed(
            ChargeJob::class,
            fn (ChargeJob $job): bool => $job->planId === (int) $plan->getKey(),
        );

        $this->assertSame(1, $run->refresh()->charges_queued);
        $this->assertSame(1, $run->not_probed, 'a fresh card needs no second lookup');
    }

    /**
     * The opposite, and it must stay: a card the issuer killed, NOT replaced since,
     * is not charged again. Eight identical declines is enough.
     */
    public function test_a_dead_card_that_was_never_replaced_is_not_charged_again(): void
    {
        Queue::fake();

        $plan = $this->plan('still-dead', PlanStatus::PAUSED, failedAt: now());
        $plan->paymentMethod->forceFill(['payplus_customer_uid' => 'cust-y'])->save();

        // The decline comes AFTER the card was last touched — nothing has changed.
        $this->travel(5)->minutes();
        $this->payment($plan, 1, PaymentStatus::FAILED, failureMessage: 'כרטיס חסום');

        $run = app(TokenRecoveryRunner::class)->start(
            $this->shop,
            [(int) $plan->getKey()],
            TokenRecoveryRun::MODE_RECOVER_AND_CHARGE,
        );

        app(TokenRecoveryRunner::class)->advance((int) $run->getKey());

        Queue::assertNotPushed(ChargeJob::class);
        $this->assertSame(0, $run->refresh()->charges_queued);
    }

    /** RELEASE BLOCKER: another shop's pass is invisible, and cannot be advanced. */
    public function test_a_pass_belongs_to_one_shop_only(): void
    {
        Queue::fake();

        $other = Shop::create([
            'woocommerce_domain' => 'other-run.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $theirRun = Tenant::run($other, function () use ($other): TokenRecoveryRun {
            $plan = $this->plan('theirs', PlanStatus::PAUSED, failedAt: now(), withCard: false);

            return app(TokenRecoveryRunner::class)->start(
                $other,
                [(int) $plan->getKey()],
                TokenRecoveryRun::MODE_RECOVER,
            );
        });

        // Back on our shop: their run is not ours to see or to walk.
        $this->assertNull(TokenRecoveryRun::query()->find($theirRun->getKey()));
        $this->assertTrue(app(TokenRecoveryRunner::class)->advance((int) $theirRun->getKey()));
        $this->assertSame(0, Tenant::run($other, fn (): int => (int) TokenRecoveryRun::query()
            ->whereKey($theirRun->getKey())->value('processed')));
    }

    /**
     * Find the card, then take the money — QUEUED, on the scheduler's own job.
     *
     * A member with a working card the issuer declined is not probed (a lookup
     * would spend a call to learn the token is valid) and goes straight to a
     * ChargeJob. A member with no card is not charged. A cancelled one is not a
     * debt anybody may collect. And a member whose token is in doubt but for whom
     * PayPlus holds nothing gets no charge at all — there is no card to ask.
     */
    public function test_find_and_charge_queues_the_schedulers_own_job_for_each_chargeable_member(): void
    {
        Queue::fake();

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function chargeWithReference($method, float $amount, string $key, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $uid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success'], 'data' => []]);
            }
        });

        // A working card the issuer declined: token not in doubt → charged.
        $declined = $this->plan('declined', PlanStatus::PAUSED, failedAt: now());
        $declined->paymentMethod->forceFill(['payplus_customer_uid' => 'cust-declined'])->save();
        $this->payment($declined, 1, PaymentStatus::FAILED);

        // No card at all → nothing to charge.
        $noCard = $this->plan('no-card', PlanStatus::PAUSED, failedAt: now(), withCard: false);
        $this->payment($noCard, 1, PaymentStatus::FAILED);

        // Cancelled → not a debt anybody may collect.
        $cancelled = $this->plan('cancelled', PlanStatus::CANCELLED, failedAt: now());
        $this->payment($cancelled, 1, PaymentStatus::FAILED);

        // Token in doubt (no customer uid) and PayPlus holds nothing → no charge.
        $orphan = $this->plan('orphan', PlanStatus::PAUSED, failedAt: now());
        $this->payment($orphan, 1, PaymentStatus::FAILED);

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->callTableBulkAction('recoverAndCharge', [$declined, $noCard, $cancelled, $orphan])
            ->assertHasNoTableBulkActionErrors();

        // Queue::fake holds the pass, so the worker's work is done here explicitly.
        $run = TokenRecoveryRun::query()->latest('id')->firstOrFail();
        $this->assertSame(TokenRecoveryRun::MODE_RECOVER_AND_CHARGE, $run->mode);

        app(TokenRecoveryRunner::class)->advance((int) $run->getKey());

        Queue::assertPushed(ChargeJob::class, 1);
        Queue::assertPushed(
            ChargeJob::class,
            fn (ChargeJob $job): bool => $job->planId === (int) $declined->getKey()
                && $job->shopId === (int) $this->shop->getKey()
                && $job->paymentType === PaymentType::RECURRING->value,
        );

        PayPlusGatewayFactory::clearFake();
    }

    /** RELEASE BLOCKER: another shop's failures are never on this screen. */
    public function test_it_never_shows_another_shops_failures(): void
    {
        $mine = $this->plan('mine', PlanStatus::AWAITING_PAYMENT);
        $this->payment($mine, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        $other = Shop::create([
            'woocommerce_domain' => 'other-recovery.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::run($other, function (): void {
            $theirs = $this->plan('theirs', PlanStatus::AWAITING_PAYMENT);
            $this->payment($theirs, 1, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());
        });

        $this->assertSame(
            1,
            PaymentRecovery::scopeFor(PaymentRecovery::TAB_RETRYING, InstallmentPlan::query())->count(),
        );
    }

    // === Fixtures ===

    private function plan(
        string $ref,
        PlanStatus $status,
        ?Carbon $failedAt = null,
        bool $withCard = true,
    ): InstallmentPlan {
        $method = $withCard
            ? InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-'.$ref,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ])
            : null;

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => Tenant::id(),
            'public_id' => 'PLN-'.$ref.'-'.uniqid(),
            'customer_name' => $ref,
            'customer_email' => $ref.'@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => $status->value,
            'payment_method_id' => $method?->getKey(),
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10),
            'payment_failed_at' => $failedAt,
            'meta' => ['item_title' => 'Club membership'],
        ])->save();

        return $plan;
    }

    private function payment(
        InstallmentPlan $plan,
        int $sequence,
        PaymentStatus $status,
        ?Carbon $nextRetry = null,
        int $attempts = 1,
        float $amount = 39.0,
        ?string $failureMessage = null,
    ): InstallmentPayment {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'sequence' => $sequence,
            'amount' => $amount,
            'currency' => 'ILS',
            'status' => $status->value,
            'attempt_count' => $attempts,
            'next_retry_at' => $nextRetry,
            'failure_code' => $failureMessage === null ? null : '1',
            'failure_message' => $failureMessage,
        ])->save();

        return $payment;
    }
}
