<?php

namespace App\Domain\Installments\Jobs;

use App\Domain\Installments\Models\TokenRecoveryRun;
use App\Domain\Installments\TokenRecoveryRunner;
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
 * Runs a "find saved cards" pass off the web request, handing itself back to the
 * queue as it goes.
 *
 * A hundred members is over a thousand round trips to PayPlus. That was being
 * attempted inside a Livewire request, and it did what it was always going to do:
 * the request died at the proxy, the merchant got a broken screen, and the cards
 * that HAD been re-pointed before it died were invisible. Nothing was corrupted —
 * but nobody could say what had happened, which on a screen about money is its own
 * kind of damage.
 *
 * Like the bulk editor, this does not try to finish in one handle(): it walks a
 * bounded number of members and RE-DISPATCHES ITSELF if there are more, so a
 * deploy or a killed container costs one member rather than a whole run.
 *
 * ShouldBeUniqueUntilProcessing, NOT ShouldBeUnique — the same distinction the
 * bulk editor depends on. A plain unique lock releases on COMPLETION, so the
 * re-dispatch from inside handle() would be swallowed as a duplicate and the run
 * would stall forever at whatever member it had reached.
 *
 * shop_id travels EXPLICITLY and TenantContext binds it for the job's lifetime.
 */
final class RunTokenRecoveryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /**
     * `sync`, not `charges`.
     *
     * Even in recover-and-charge mode this job takes no money itself — it queues
     * ChargeJobs, which the charges queue then drains. Walking a recovery on the
     * charges queue would let a long lookup pass sit in front of real charges that
     * are due, on a worker pool of ten shared across every queue.
     */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** Unique-lock TTL. Generous: it guards a duplicate dispatch, not a slow run. */
    public int $uniqueFor = 3600;

    /**
     * Three attempts, safe ONLY because the runner commits each member — counters
     * and cursor together — before moving on. A retry therefore resumes from the
     * last committed member and redoes at most one, and redoing one costs a single
     * read-only lookup at PayPlus (the token it now holds answers ALREADY_VALID).
     */
    public int $tries = 3;

    /** @var list<int> seconds between attempts. */
    public array $backoff = [10, 60];

    /**
     * Long enough for MEMBERS_PER_JOB of gateway round trips on a slow day, short
     * enough to stay under the queue connection's retry_after — otherwise Redis
     * hands this run to a second worker while the first is still committing.
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
        return sprintf('shop:%d:token_recovery:%d', $this->shopId, $this->runId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(TokenRecoveryRunner $runner): void
    {
        $finished = $runner->advance($this->runId);

        if (! $finished) {
            // Back onto the queue for the next slice. The unique lock is already
            // released (UntilProcessing), so this dispatch is accepted.
            self::dispatch($this->shopId, $this->runId);
        }
    }

    /**
     * Every attempt is gone. Say so ON THE RUN ROW.
     *
     * A run left reading "running" forever would have the screen polling a worker
     * that is never coming back, while the merchant waits for an answer about
     * whose cards were fixed.
     */
    public function failed(Throwable $e): void
    {
        Log::error('token_recovery.job_failed', [
            'run_id' => $this->runId,
            'shop_id' => $this->shopId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        // The TenantContext middleware has already unwound by the time this hook
        // runs, so the tenant is re-bound EXPLICITLY rather than the global scope
        // being switched off — the scope stays on, pointed at this job's own shop.
        $shop = Shop::query()->find($this->shopId);

        if ($shop === null) {
            return;
        }

        Tenant::run($shop, function () use ($e): void {
            TokenRecoveryRun::query()
                ->whereKey($this->runId)
                ->whereIn('status', [TokenRecoveryRun::STATUS_QUEUED, TokenRecoveryRun::STATUS_RUNNING])
                ->update([
                    'status' => TokenRecoveryRun::STATUS_FAILED,
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }
}
