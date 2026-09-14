<?php

namespace Tests\Feature\Bulk;

use App\Domain\Bulk\BulkEditRunner;
use App\Domain\Bulk\BulkOperationRegistry;
use App\Domain\Bulk\InvalidBulkEdit;
use App\Domain\Bulk\Jobs\RunBulkSubscriptionEditJob;
use App\Domain\Bulk\Models\BulkSubscriptionEdit;
use App\Domain\Bulk\Operations\LifecycleEdit;
use App\Domain\Bulk\Operations\PauseSubscriptions;
use App\Domain\Bulk\Operations\SetNextChargeDate;
use App\Domain\Bulk\Operations\ShiftNextChargeDate;
use App\Domain\Bulk\SubscriptionCriteria;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The engine: walking tens of thousands of subscriptions without loading them,
 * without doing any of them twice, and without leaving a half-finished run nobody
 * can read.
 *
 * These are the tests that justify the design over a foreach. They pin the four
 * properties the class docblock claims — bounded cost, a resumable cursor, a
 * retried chunk that redoes nothing, and immunity to an operation that moves rows
 * out of its own filter — and the two ways a run ends badly: an error that must
 * land on the row, and a merchant who stops it halfway.
 */
final class BulkEditRunnerTest extends TestCase
{
    use MakesBulkSubscriptions;
    use RefreshDatabase;

    /** Enough rows to need several chunks of the set-based operations. */
    private const AT_SCALE = 1_100;

    private Shop $shop;

    private BulkEditRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop();
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $this->runner = app(BulkEditRunner::class);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_starting_a_run_writes_a_receipt_and_queues_a_worker(): void
    {
        Queue::fake();

        $this->makePlan($this->shop, 'one');
        $this->makePlan($this->shop, 'two');

        $run = $this->start(['date' => '2026-11-01']);

        $this->assertSame(BulkSubscriptionEdit::STATUS_QUEUED, $run->status);
        $this->assertSame(SetNextChargeDate::KEY, $run->operation);
        $this->assertSame(2, $run->matched_count);
        $this->assertSame(2, $run->eligible_count);
        $this->assertSame('2026-11-01', $run->params['date']);
        $this->assertNotNull($run->summary, 'the receipt names what was asked for');
        $this->assertNotNull($run->requested_by, 'and who asked');

        Queue::assertPushed(RunBulkSubscriptionEditJob::class);
    }

    /** Nothing to do is a refusal, not a run that finishes having done nothing. */
    public function test_a_run_that_would_match_nothing_is_refused_before_it_exists(): void
    {
        Queue::fake();

        $this->expectException(InvalidBulkEdit::class);

        try {
            $this->start(['date' => '2026-11-01']);
        } finally {
            $this->assertSame(0, BulkSubscriptionEdit::query()->count());
            Queue::assertNothingPushed();
        }
    }

    public function test_it_walks_the_whole_matched_set_across_several_chunks(): void
    {
        Queue::fake();
        $this->makePlans($this->shop, self::AT_SCALE);

        $run = $this->start(['date' => '2026-11-01']);

        $this->assertTrue($this->runner->advance((int) $run->getKey()));

        $fresh = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(self::AT_SCALE, $fresh->processed_count);
        $this->assertSame(self::AT_SCALE, $fresh->changed_count);
        $this->assertSame(0, $fresh->skipped_count);
        $this->assertSame(0, $fresh->failed_count);
        $this->assertNotNull($fresh->finished_at);

        $this->assertSame(
            0,
            InstallmentPlan::query()->whereDate('next_charge_at', '!=', '2026-11-01')->count(),
            'every matched subscription moved',
        );
    }

    /**
     * THE SCALE CLAIM, measured.
     *
     * The cost of a run is a function of CHUNKS, not of rows: eleven hundred
     * subscriptions are moved in the same handful of statements that five hundred
     * would take, plus one more round per chunk. A per-row implementation would
     * need three or four thousand queries for the same work, which is the
     * difference between a bulk edit that finishes and one that times out at
     * forty thousand.
     */
    public function test_its_cost_is_per_chunk_and_not_per_row(): void
    {
        Queue::fake();
        $this->makePlans($this->shop, self::AT_SCALE);

        $run = $this->start(['date' => '2026-11-01']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->runner->advance((int) $run->getKey());

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $chunks = (int) ceil(self::AT_SCALE / SetNextChargeDate::CHUNK);

        $this->assertLessThan(
            60,
            $queries,
            sprintf('%d subscriptions in %d chunks should cost tens of queries, not thousands; took %d', self::AT_SCALE, $chunks, $queries),
        );

        // And the audit is one row per subscription regardless — written per chunk,
        // in one insert each.
        $this->assertSame(
            self::AT_SCALE,
            ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_EDITED)->count(),
        );
    }

    /**
     * A run stops when its job's slice is used up, and continues where it stopped.
     *
     * This is what lets a run survive a deploy: the cursor is on the row, so the
     * next job — minutes or an hour later — picks the walk up mid-book.
     */
    public function test_a_run_resumes_from_its_committed_cursor(): void
    {
        Queue::fake();
        $this->makePlans($this->shop, self::AT_SCALE);

        $run = $this->start(['date' => '2026-11-01']);

        // One chunk only — as if the worker had been killed straight afterwards.
        $this->assertFalse($this->runner->advance((int) $run->getKey(), maxChunks: 1));

        $afterOne = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_RUNNING, $afterOne->status);
        $this->assertSame(SetNextChargeDate::CHUNK, $afterOne->processed_count);
        $this->assertGreaterThan(0, $afterOne->cursor_id);

        // A fresh worker finishes the rest.
        $this->assertTrue($this->runner->advance((int) $run->getKey()));

        $done = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_COMPLETED, $done->status);
        $this->assertSame(self::AT_SCALE, $done->processed_count);
    }

    /**
     * A NON-IDEMPOTENT verb, interrupted and resumed, still moves each row ONCE.
     *
     * "Shift everybody a week later" is the operation that cannot survive being
     * applied twice, so the resumed walk is checked against the exact date rather
     * than against a count. Every subscription started on the same day; every one
     * of them must land exactly seven days later, whichever chunk it was in.
     */
    public function test_an_interrupted_shift_moves_each_subscription_exactly_once(): void
    {
        Queue::fake();
        $start = now()->addMonth()->startOfDay();
        $this->makePlans($this->shop, 700, $start->toDateTimeString());

        $run = $this->start(
            ['amount' => 7, 'unit' => ShiftNextChargeDate::UNIT_DAYS],
            ShiftNextChargeDate::KEY,
        );

        $this->assertFalse($this->runner->advance((int) $run->getKey(), maxChunks: 1));
        $this->assertTrue($this->runner->advance((int) $run->getKey()));

        $expected = $start->copy()->addDays(7)->toDateString();

        $this->assertSame(
            0,
            InstallmentPlan::query()->whereDate('next_charge_at', '!=', $expected)->count(),
            'no subscription was shifted twice, and none was missed',
        );
    }

    /**
     * An operation can move rows OUT of the filter that selected them, and the walk
     * must not notice.
     *
     * Shifting a date forward past the end of a date range is the obvious case. The
     * cursor is on `id`, so rows already passed cannot come back and rows not yet
     * reached cannot be disturbed — a cursor on the filtered column would have
     * chased its own tail.
     */
    public function test_a_walk_is_immune_to_the_operation_moving_rows_out_of_its_own_filter(): void
    {
        Queue::fake();
        $start = now()->addMonth()->startOfDay();
        $this->makePlans($this->shop, 700, $start->toDateTimeString());

        // A filter that every row leaves the moment it is shifted.
        $run = $this->start(
            ['amount' => 7, 'unit' => ShiftNextChargeDate::UNIT_DAYS],
            ShiftNextChargeDate::KEY,
            [
                'next_charge_from' => $start->toDateString(),
                'next_charge_until' => $start->toDateString(),
            ],
        );

        $this->assertTrue($this->runner->advance((int) $run->getKey()));

        $done = $run->fresh();
        $this->assertSame(700, $done->processed_count);
        $this->assertSame(700, $done->changed_count);
        $this->assertSame(
            700,
            InstallmentPlan::query()->whereDate('next_charge_at', $start->copy()->addDays(7)->toDateString())->count(),
        );
    }

    /**
     * A chunk that dies half-written leaves NOTHING behind.
     *
     * The property everything else depends on: rows, audit and cursor commit
     * together, so the retry that follows redoes a chunk that never happened rather
     * than doubling one that did.
     */
    public function test_a_chunk_that_fails_leaves_no_trace_and_the_run_says_why(): void
    {
        Queue::fake();
        $plan = $this->makePlan($this->shop, 'untouched', ['next_charge_at' => '2026-10-03 00:00:00']);

        $run = $this->start(['date' => '2026-11-01']);

        // The registry resolves through the container, so the run now meets an
        // operation that writes its chunk and then falls over.
        $this->app->bind(SetNextChargeDate::class, fn (): HalfWrittenChunkOperation => new HalfWrittenChunkOperation);

        $this->assertTrue($this->runner->advance((int) $run->getKey()));

        $this->assertSame('2026-10-03', $plan->fresh()->next_charge_at->toDateString(), 'the write rolled back');
        $this->assertSame(0, ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_EDITED)->count(), 'so did the audit');

        $failed = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_FAILED, $failed->status);
        $this->assertSame(0, $failed->cursor_id, 'the cursor did not advance past the chunk');
        $this->assertStringContainsString(HalfWrittenChunkOperation::MESSAGE, (string) $failed->error);
        $this->assertNotNull($failed->finished_at);
    }

    /** A merchant can stop a long run. What already committed is real and stays. */
    public function test_stopping_a_run_halts_the_walk_and_keeps_what_landed(): void
    {
        Queue::fake();
        $this->makePlans($this->shop, self::AT_SCALE);

        $run = $this->start(['date' => '2026-11-01']);

        $this->runner->advance((int) $run->getKey(), maxChunks: 1);
        $this->runner->cancel((int) $run->getKey());

        $stopped = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_CANCELLED, $stopped->status);

        $landed = (int) $stopped->processed_count;
        $this->assertSame(SetNextChargeDate::CHUNK, $landed);

        // A worker that comes back finds the run over and walks no further.
        $this->assertTrue($this->runner->advance((int) $run->getKey()));
        $this->assertSame($landed, (int) $run->fresh()->processed_count);

        $this->assertSame(
            $landed,
            InstallmentPlan::query()->whereDate('next_charge_at', '2026-11-01')->count(),
            'the committed chunk stands',
        );
    }

    /** A finished run is inert — a duplicate job cannot re-run it. */
    public function test_a_finished_run_is_not_walked_again(): void
    {
        Queue::fake();
        $this->makePlan($this->shop, 'one');

        $run = $this->start(['date' => '2026-11-01']);
        $this->runner->advance((int) $run->getKey());

        $processed = (int) $run->fresh()->processed_count;

        $this->assertTrue($this->runner->advance((int) $run->getKey()));
        $this->assertSame($processed, (int) $run->fresh()->processed_count);
    }

    /**
     * A run whose stored verb this build does not know FAILS, with the reason on
     * the row.
     *
     * The shape a deploy can produce: a run queued by the previous build, walked by
     * the next one. The honest outcome is a failed run a merchant can read, not a
     * job that dies three times while the row still says "queued".
     */
    public function test_a_run_naming_an_unknown_verb_fails_with_a_reason(): void
    {
        Queue::fake();
        $this->makePlan($this->shop, 'one');

        $run = $this->queuedRun();
        // Rewritten behind the model, as a stale row in the database would be.
        DB::table('bulk_subscription_edits')->where('id', $run->getKey())->update(['operation' => 'verb_from_the_future']);

        $this->assertTrue($this->runner->advance((int) $run->getKey()));

        $failed = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_FAILED, $failed->status);
        $this->assertNotNull($failed->error);
        $this->assertSame(0, $failed->processed_count);
    }

    /**
     * While there is more to walk, the job hands itself back to the QUEUE instead
     * of finishing the run in one handle() — which is what lets a deploy or a
     * killed container cost one chunk rather than a whole run.
     *
     * Sized to overflow one job's slice by a single subscription: the per-row
     * lifecycle verb commits a hundred at a time, so twenty chunks is two thousand,
     * and the two-thousand-and-first is what must come back for a second job.
     */
    public function test_the_job_re_dispatches_itself_while_there_is_more_to_do(): void
    {
        Queue::fake();

        $overflow = (BulkEditRunner::CHUNKS_PER_JOB * LifecycleEdit::CHUNK) + 1;
        $this->makePlans($this->shop, $overflow);

        // The run row is written WITHOUT start()'s dispatch on purpose. A dispatch
        // takes the job's unique lock, and in production that lock is released the
        // moment a worker begins processing (ShouldBeUniqueUntilProcessing) — which
        // is precisely what allows the self-dispatch below. Calling handle()
        // directly skips that release, so the test starts from no lock at all.
        $run = $this->queuedRun(PauseSubscriptions::KEY);

        (new RunBulkSubscriptionEditJob((int) $this->shop->getKey(), (int) $run->getKey()))
            ->handle(app(BulkEditRunner::class));

        $this->assertSame($overflow - 1, (int) $run->fresh()->processed_count, 'one job walked one slice');
        $this->assertSame(BulkSubscriptionEdit::STATUS_RUNNING, $run->fresh()->status);

        Queue::assertPushed(RunBulkSubscriptionEditJob::class, 1);
    }

    public function test_the_job_stops_dispatching_once_the_run_is_over(): void
    {
        Queue::fake();
        $this->makePlan($this->shop, 'only-one');

        $run = $this->queuedRun();

        (new RunBulkSubscriptionEditJob((int) $this->shop->getKey(), (int) $run->getKey()))
            ->handle(app(BulkEditRunner::class));

        $this->assertSame(BulkSubscriptionEdit::STATUS_COMPLETED, $run->fresh()->status);

        Queue::assertNothingPushed();
    }

    /**
     * A job whose every attempt is gone marks the run FAILED.
     *
     * A run left reading "running" forever is the worst outcome available here: the
     * merchant cannot tell whether their subscriptions were changed, and the screen
     * would poll a worker that is never coming back.
     */
    public function test_an_exhausted_job_marks_the_run_failed(): void
    {
        Queue::fake();
        $this->makePlan($this->shop, 'one');

        $run = $this->start(['date' => '2026-11-01']);

        (new RunBulkSubscriptionEditJob((int) $this->shop->getKey(), (int) $run->getKey()))
            ->failed(new \RuntimeException('queue gave up'));

        $failed = $run->fresh();
        $this->assertSame(BulkSubscriptionEdit::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('queue gave up', (string) $failed->error);
    }

    /** RELEASE BLOCKER: a run only ever touches its own shop's subscriptions. */
    public function test_a_run_never_reaches_another_shops_subscriptions(): void
    {
        Queue::fake();

        $mine = $this->makePlan($this->shop, 'mine', ['next_charge_at' => '2026-10-03 00:00:00']);

        $other = $this->makeShop('other.example.com');
        Tenant::set($other);
        $theirs = $this->makePlan($other, 'theirs', ['next_charge_at' => '2026-10-03 00:00:00']);
        Tenant::set($this->shop);

        $run = $this->start(['date' => '2026-11-01']);

        $this->assertSame(1, $run->eligible_count, 'the count saw one shop only');

        $this->runner->advance((int) $run->getKey());

        $this->assertSame('2026-11-01', $mine->fresh()->next_charge_at->toDateString());

        Tenant::set($other);
        $this->assertSame('2026-10-03', $theirs->fresh()->next_charge_at->toDateString(), 'untouched');
        Tenant::set($this->shop);
    }

    /**
     * A worker carrying ANOTHER shop's run id finds nothing.
     *
     * The job binds its own tenant, so the lookup fails closed rather than the run
     * being advanced against the wrong shop's subscriptions.
     */
    public function test_a_run_id_from_another_shop_resolves_to_nothing(): void
    {
        Queue::fake();
        $this->makePlan($this->shop, 'mine');
        $run = $this->start(['date' => '2026-11-01']);

        $other = $this->makeShop('other.example.com');
        Tenant::set($other);

        $this->assertTrue($this->runner->advance((int) $run->getKey()), 'nothing to do');

        Tenant::set($this->shop);
        $this->assertSame(BulkSubscriptionEdit::STATUS_QUEUED, $run->fresh()->status, 'never started');
    }

    // === Helpers ===

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $criteria
     */
    private function start(array $params, string $operation = SetNextChargeDate::KEY, array $criteria = []): BulkSubscriptionEdit
    {
        return $this->runner->start(
            shop: $this->shop,
            criteria: SubscriptionCriteria::fromArray($criteria),
            operationKey: $operation,
            params: $params,
        );
    }

    /**
     * A queued run row, written WITHOUT start()'s dispatch — the state a worker
     * finds, with no unique lock held. @see the re-dispatch test for why.
     *
     * @param  array<string, mixed>  $params
     */
    private function queuedRun(string $operation = SetNextChargeDate::KEY, array $params = ['date' => '2026-11-01']): BulkSubscriptionEdit
    {
        $normalised = app(BulkOperationRegistry::class)->get($operation)->normalise($params);

        $run = new BulkSubscriptionEdit;
        $run->forceFill([
            'shop_id' => $this->shop->getKey(),
            'operation' => $operation,
            'params' => $normalised,
            'criteria' => SubscriptionCriteria::fromArray([])->toArray(),
            'status' => BulkSubscriptionEdit::STATUS_QUEUED,
            'matched_count' => 0,
            'eligible_count' => 0,
        ])->save();

        return $run;
    }
}
