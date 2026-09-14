<?php

namespace App\Domain\Bulk;

use App\Domain\Bulk\Jobs\RunBulkSubscriptionEditJob;
use App\Domain\Bulk\Models\BulkSubscriptionEdit;
use App\Models\Shop;
use App\Support\PlatformContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks a bulk edit's matched set, a chunk at a time, and is the ONLY thing that
 * writes to a run row.
 *
 * FOUR PROPERTIES, AND EVERY ONE OF THEM IS WHY THIS IS NOT A FOREACH.
 *
 * 1. BOUNDED MEMORY. The target set is a query, never a list of ids: forty
 *    thousand subscriptions are read `chunkSize()` at a time by an id cursor, so
 *    the run costs the same heap at forty thousand rows as at forty.
 *
 * 2. RESUMABLE. `cursor_id` is the highest id whose chunk COMMITTED, and it is
 *    committed in the same transaction as the rows and their audit. A worker
 *    killed mid-chunk leaves a run that picks up exactly where it committed — the
 *    interrupted chunk having left no trace at all.
 *
 * 3. RETRY-SAFE, EVEN FOR A NON-IDEMPOTENT VERB. "Shift every date a week later"
 *    run twice legitimately shifts twice, so a retried chunk must be a chunk that
 *    never happened. The transaction is what makes that true, and it is why the
 *    audit rows are inside it rather than written afterwards.
 *
 * 4. IMMUNE TO ITS OWN CHANGES. An operation can move rows out of the very filter
 *    that selected them (shift a date past the end of the date range). The cursor
 *    is on `id`, not on the filtered column, so rows already passed cannot return
 *    and rows not yet reached cannot be disturbed.
 *
 * The run row is LOCKED for the duration of each chunk. That is what stops two
 * workers — a retry racing the attempt it was meant to replace — from reading one
 * cursor and applying one chunk twice.
 */
final class BulkEditRunner
{
    // === CONSTANTS ===
    /**
     * Chunks per job invocation, before the job re-dispatches itself.
     *
     * The queue, not the job, is what survives a deploy or a killed container, so
     * a run hands itself back to the queue regularly instead of sitting in one
     * hour-long handle(). Twenty chunks is ten thousand rows of set-based work or
     * two thousand of per-row work — minutes, not an hour.
     */
    public const CHUNKS_PER_JOB = 20;

    public function __construct(
        private readonly BulkOperationRegistry $registry,
        private readonly BulkEditPlanner $planner,
    ) {}

    /**
     * Accept a bulk edit: write the receipt, then hand it to a worker.
     *
     * The plan is recomputed HERE rather than trusted from the screen's state. The
     * merchant confirmed a number, but the number they confirmed reached us through
     * a browser, and the count stored on the run must be one this server counted.
     *
     * @param  array<string, mixed>  $params  raw, from the screen
     *
     * @throws InvalidBulkEdit when the operation or its parameters are not runnable
     */
    public function start(Shop $shop, SubscriptionCriteria $criteria, string $operationKey, array $params): BulkSubscriptionEdit
    {
        $operation = $this->registry->get($operationKey);
        $normalised = $operation->normalise($params);
        $plan = $this->planner->plan($criteria, $operation, $normalised);

        if ($plan->isEmpty()) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.nothing_matched');
        }

        $run = new BulkSubscriptionEdit;
        $run->forceFill([
            'shop_id' => $shop->getKey(),
            'operation' => $operation->key(),
            'params' => $normalised,
            'criteria' => $criteria->toArray(),
            'status' => BulkSubscriptionEdit::STATUS_QUEUED,
            'matched_count' => $plan->matched,
            'eligible_count' => $plan->eligible,
            'requested_by' => Auth::id(),
            // Frozen now, because the worker cannot resolve it later: by the time a
            // chunk runs, the request that asked for it is gone and
            // PlatformContext would honestly answer "system".
            'actor' => PlatformContext::actingActor(),
            'summary' => mb_substr($plan->summary, 0, 500),
        ])->save();

        RunBulkSubscriptionEditJob::dispatch((int) $shop->getKey(), (int) $run->getKey());

        Log::info('bulk_edit.started', [
            'bulk_edit_id' => $run->getKey(),
            'shop_id' => $shop->getKey(),
            'operation' => $operation->key(),
            'eligible' => $plan->eligible,
        ]);

        return $run;
    }

    /**
     * Process up to $maxChunks chunks of a run.
     *
     * @return bool true when the run is over (finished, failed, cancelled, or gone)
     *              and needs no further job; false when there is more to do
     */
    public function advance(int $runId, int $maxChunks = self::CHUNKS_PER_JOB): bool
    {
        // Tenant-scoped: a run id from another shop resolves to null, and the job
        // that carried it simply has nothing to do.
        $run = BulkSubscriptionEdit::query()->find($runId);

        if ($run === null || $run->isFinished()) {
            return true;
        }

        try {
            // Inside the try on purpose. Resolving a stored operation key can
            // legitimately fail — a run queued by a build that knew a verb this one
            // does not — and the honest outcome is a FAILED run with the reason on
            // the row, not three queue attempts and a job that dies before the
            // status was ever moved off "queued".
            $operation = $this->registry->get((string) $run->operation);
            $criteria = SubscriptionCriteria::fromArray((array) ($run->criteria ?? []));
            $params = (array) ($run->params ?? []);
            $context = BulkEditContext::for($run);
            $chunkSize = $operation->chunkSize();

            if ((string) $run->status === BulkSubscriptionEdit::STATUS_QUEUED) {
                $run->forceFill([
                    'status' => BulkSubscriptionEdit::STATUS_RUNNING,
                    'started_at' => now(),
                ])->save();
            }

            for ($i = 0; $i < $maxChunks; $i++) {
                // A merchant can stop a long run. Asked before every chunk, as one
                // column read, so "Stop" takes effect within a chunk rather than
                // when the whole run would have ended anyway.
                if ($this->statusOf($runId) !== BulkSubscriptionEdit::STATUS_RUNNING) {
                    return true;
                }

                $processed = $this->chunk($runId, $criteria, $operation, $params, $context, $chunkSize);

                if ($processed === 0) {
                    // Exhausted — unless somebody stopped it while this chunk ran,
                    // in which case the run is already over and must not be
                    // re-labelled as having completed.
                    if ($this->statusOf($runId) === BulkSubscriptionEdit::STATUS_RUNNING) {
                        $this->finish($runId, BulkSubscriptionEdit::STATUS_COMPLETED);
                    }

                    return true;
                }
            }
        } catch (Throwable $e) {
            $this->markFailed($runId, $e);

            return true;
        }

        return false; // more to walk — the job re-dispatches itself
    }

    /**
     * ONE chunk: read, apply, audit, advance the cursor — all in one transaction.
     *
     * @param  array<string, mixed>  $params  normalised
     * @return int rows processed (0 = the matched set is exhausted)
     */
    private function chunk(
        int $runId,
        SubscriptionCriteria $criteria,
        BulkOperation $operation,
        array $params,
        BulkEditContext $context,
        int $chunkSize,
    ): int {
        return DB::transaction(function () use ($runId, $criteria, $operation, $params, $context, $chunkSize): int {
            // The run row is the baton. Locking it serialises two workers on one
            // run — a queue retry racing the attempt it was meant to replace would
            // otherwise read the same cursor and apply the same chunk twice, which
            // for a shift means shifting twice.
            $run = BulkSubscriptionEdit::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run === null || $run->isFinished()) {
                return 0;
            }

            $plans = $operation->eligible($criteria->apply(), $params)
                ->where('id', '>', (int) $run->cursor_id)
                ->orderBy('id')
                ->limit($chunkSize)
                // Locked for the length of the chunk so the read and the write are
                // atomic against the per-plan paths (a customer cancelling, a
                // charge landing) — both of which take the same row lock. Always
                // in id order, so two runs can never deadlock against each other.
                ->lockForUpdate()
                ->get();

            if ($plans->isEmpty()) {
                return 0;
            }

            $outcome = $operation->apply($plans, $params, $context);

            $run->forceFill([
                // The highest id THIS chunk read — including rows the operation
                // skipped, or a skipped row would be re-read forever.
                'cursor_id' => (int) $plans->max('id'),
                'processed_count' => (int) $run->processed_count + $outcome->total(),
                'changed_count' => (int) $run->changed_count + $outcome->changed,
                'skipped_count' => (int) $run->skipped_count + $outcome->skipped,
                'failed_count' => (int) $run->failed_count + $outcome->failed,
            ])->save();

            return $plans->count();
        });
    }

    /** A merchant stopping a run mid-flight. Committed chunks stand — they are real. */
    public function cancel(int $runId): void
    {
        $run = BulkSubscriptionEdit::query()->find($runId);

        if ($run === null || $run->isFinished()) {
            return;
        }

        $run->forceFill([
            'status' => BulkSubscriptionEdit::STATUS_CANCELLED,
            'finished_at' => now(),
        ])->save();

        Log::info('bulk_edit.cancelled', [
            'bulk_edit_id' => $runId,
            'processed' => $run->processed_count,
        ]);
    }

    /** The status column alone — the cancellation check, once per chunk. */
    private function statusOf(int $runId): ?string
    {
        $status = BulkSubscriptionEdit::query()->whereKey($runId)->value('status');

        return $status === null ? null : (string) $status;
    }

    private function finish(int $runId, string $status): void
    {
        BulkSubscriptionEdit::query()->whereKey($runId)->update([
            'status' => $status,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('bulk_edit.finished', ['bulk_edit_id' => $runId, 'status' => $status]);
    }

    /**
     * Stop the run and say why, on the row.
     *
     * The reason lands where the merchant is already looking, not only in a log
     * they cannot reach — and the cursor stays put, so the receipt says how far it
     * got before it stopped.
     */
    private function markFailed(int $runId, Throwable $e): void
    {
        Log::error('bulk_edit.failed', [
            'bulk_edit_id' => $runId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        BulkSubscriptionEdit::query()->whereKey($runId)->update([
            'status' => BulkSubscriptionEdit::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The shop's recent runs, newest first — the receipt book on the screen.
     *
     * @return Collection<int, BulkSubscriptionEdit>
     */
    public function history(int $limit = BulkSubscriptionEdit::HISTORY_LIMIT): Collection
    {
        return BulkSubscriptionEdit::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
