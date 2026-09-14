<?php

namespace App\Domain\Bulk\Jobs;

use App\Domain\Bulk\BulkEditRunner;
use App\Domain\Bulk\Models\BulkSubscriptionEdit;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a bulk edit off the web request, and hands itself back to the queue as it
 * goes.
 *
 * A merchant who moves forty thousand charge dates is asking for more work than a
 * browser request may hold — the same reason the CSV import writes from a worker.
 * But unlike an import, this job does not try to finish in one handle(): it
 * processes a bounded number of chunks and RE-DISPATCHES ITSELF if there is more,
 * so a deploy or a killed container costs one chunk rather than a whole run. The
 * cursor on the run row is what makes that free.
 *
 * ShouldBeUniqueUntilProcessing, NOT ShouldBeUnique. The distinction is the whole
 * mechanism: a plain unique lock is released when the job COMPLETES, so the
 * re-dispatch from inside handle() would be silently swallowed as a duplicate and
 * the run would stall forever at whatever chunk it had reached. Releasing on
 * PROCESSING lets this job queue its own successor while still keeping two workers
 * off the same run.
 *
 * shop_id travels EXPLICITLY and TenantContext binds it for the job's lifetime —
 * a bulk edit is the last place a tenant should be inferred from anything.
 */
final class RunBulkSubscriptionEditJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** Unique-lock TTL. Generous: it guards against a duplicate dispatch, not a slow run. */
    public int $uniqueFor = 3600;

    /**
     * Three attempts, which is safe ONLY because the runner commits each chunk —
     * rows, audit and cursor — in one transaction. A retry therefore resumes from
     * the last committed chunk and redoes work that was rolled back, never work
     * that landed. Without that transaction this would have to be tries = 1.
     */
    public int $tries = 3;

    /** @var list<int> seconds between attempts — a deadlock clears in seconds, a DB blip in a minute. */
    public array $backoff = [10, 60];

    /**
     * Long enough for CHUNKS_PER_JOB of per-row lifecycle work, short enough that
     * the queue connection's retry_after can stay above it (Railway's
     * REDIS_QUEUE_RETRY_AFTER) — otherwise Redis hands the job to a second worker
     * while the first is still committing chunks.
     */
    public int $timeout = 600;

    public function __construct(
        /** The tenant, carried explicitly — never inferred from global state. */
        public readonly int $shopId,
        public readonly int $runId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** One in-flight job per run. */
    public function uniqueId(): string
    {
        return sprintf('shop:%d:bulk_edit:%d', $this->shopId, $this->runId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(BulkEditRunner $runner): void
    {
        $finished = $runner->advance($this->runId);

        if (! $finished) {
            // Back onto the queue for the next slice. The unique lock is already
            // released (UntilProcessing), so this dispatch is accepted.
            self::dispatch($this->shopId, $this->runId);
        }
    }

    /**
     * Every attempt is gone. Say so ON THE RUN ROW, not only in a log.
     *
     * A run left reading "running" forever is the worst outcome here: the merchant
     * cannot tell whether their forty thousand subscriptions were changed, and the
     * screen would poll a worker that is never coming back. The cursor stays where
     * it committed, so the receipt still says how far it got.
     */
    public function failed(Throwable $e): void
    {
        Log::error('bulk_edit.job_failed', [
            'bulk_edit_id' => $this->runId,
            'shop_id' => $this->shopId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        // The job's TenantContext middleware has already unwound by the time this
        // hook runs, so the tenant is re-bound EXPLICITLY here rather than the
        // global scope being switched off. A bulk edit is the last place to open
        // that door, and Tenant::run is the same mechanism the middleware uses —
        // the scope stays on, and it stays pointed at this job's own shop.
        $shop = Shop::query()->find($this->shopId);

        if ($shop === null) {
            return;
        }

        Tenant::run($shop, function () use ($e): void {
            BulkSubscriptionEdit::query()
                ->whereKey($this->runId)
                ->whereIn('status', [BulkSubscriptionEdit::STATUS_QUEUED, BulkSubscriptionEdit::STATUS_RUNNING])
                ->update([
                    'status' => BulkSubscriptionEdit::STATUS_FAILED,
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }
}
