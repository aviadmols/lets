<?php

namespace App\Domain\Import\Jobs;

use App\Domain\Import\ImportOptions;
use App\Domain\Import\SubscriptionImporter;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a migration file off the web request.
 *
 * A merchant migrating a store uploads a file with thousands of members in it, and
 * a browser request is the wrong place to spend that time — it dies at the gateway
 * halfway through and leaves nobody able to say what was written. So the screen
 * dry-runs (fast, no writes) and hands the WRITE to a worker.
 *
 * The file travels through the CACHE rather than the filesystem, because web and
 * worker are separate containers on Railway and do not share a disk: a path
 * written by the request simply does not exist for the worker. Redis is shared,
 * which is exactly the property needed here.
 *
 * NOT retryable. Everything else in this system keys its writes to an idempotency
 * key; an import's safety comes from the scan instead, and a half-finished run
 * re-run from the top would be judged against a store it has already changed. One
 * attempt, and a failure a human reads.
 */
final class ImportSubscriptionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** Cache key holding the uploaded CSV, keyed by run id. */
    public const FILE_KEY = 'import:file:';

    /** Cache key holding the run's status + report. */
    public const RESULT_KEY = 'import:result:';

    /** How long a run's file and its result stay readable. */
    public const TTL_MINUTES = 120;

    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    /** One attempt — see the class docblock. */
    public int $tries = 1;

    /**
     * Long enough for a real store's file.
     *
     * WITHOUT this the job inherits the Horizon supervisor's 60 seconds, which is
     * sized for a charge, not for a migration: a 765KB export is thousands of
     * subscribers and the worker would be killed part-way through — and with
     * `tries = 1` there is no second attempt, so the merchant is left with a
     * half-imported store and a "failed" they cannot act on.
     *
     * The queue connection's `retry_after` MUST stay above this (Railway sets
     * REDIS_QUEUE_RETRY_AFTER), or Redis hands the job to a second worker while
     * the first is still writing.
     */
    public int $timeout = 900;

    public function __construct(
        /** The tenant, carried explicitly — never inferred from global state. */
        public readonly int $shopId,
        public readonly string $runId,
        public readonly ImportOptions $options,
    ) {
        $this->onQueue(TenantContext::QUEUE_SYNC);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(SubscriptionImporter $importer): void
    {
        self::putResult($this->runId, ['state' => self::STATE_RUNNING]);

        $contents = Cache::get(self::FILE_KEY.$this->runId);

        if (! is_string($contents) || $contents === '') {
            self::putResult($this->runId, [
                'state' => self::STATE_FAILED,
                'message' => 'The uploaded file expired before the import could run. Upload it again.',
            ]);

            return;
        }

        // A worker-local scratch file: the reader streams from a handle, which is
        // what keeps a fifty-thousand-row file off the heap.
        $path = tempnam(sys_get_temp_dir(), 'lets-import-');

        try {
            file_put_contents($path, $contents);
            unset($contents);

            $shop = Shop::query()->findOrFail($this->shopId);
            $report = $importer->import($shop, $path, $this->options);

            self::putResult($this->runId, [
                'state' => self::STATE_DONE,
                'report' => $report->toArray(),
            ]);
        } catch (Throwable $e) {
            Log::error('import.subscriptions.failed', [
                'shop_id' => $this->shopId,
                'run_id' => $this->runId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            self::putResult($this->runId, [
                'state' => self::STATE_FAILED,
                'message' => $e->getMessage(),
            ]);
        } finally {
            @unlink($path);
            Cache::forget(self::FILE_KEY.$this->runId);
        }
    }

    /**
     * Park an uploaded file where any container can read it.
     *
     * Deliberately separate from queue(): the file is stashed at CHECK time, while
     * the request certainly holds it, and the import runs from the stash. Reaching
     * back for Livewire's temporary upload on a later request is a bet that the
     * click lands on the same web container that took the upload — a bet that loses
     * quietly, and looks to the merchant like a button that does nothing.
     */
    public static function stash(string $runId, string $contents): void
    {
        Cache::put(self::FILE_KEY.$runId, $contents, now()->addMinutes(self::TTL_MINUTES));
    }

    /** Is the stashed file still there? (It has a TTL; a page left open all day is not.) */
    public static function hasStash(string $runId): bool
    {
        return Cache::has(self::FILE_KEY.$runId);
    }

    /**
     * Queue the import of an already-stashed file.
     *
     * NOT named queue(): Laravel's dispatcher treats a `queue` method on a job as
     * the custom-queueing hook and calls it with its own arguments, which turns a
     * dispatch into a TypeError deep inside the framework.
     */
    public static function enqueue(int $shopId, string $runId, ImportOptions $options): void
    {
        self::putResult($runId, ['state' => self::STATE_QUEUED]);

        self::dispatch($shopId, $runId, $options);
    }

    /** Stash + queue in one call (the CLI-shaped entry point). */
    public static function start(int $shopId, string $runId, string $contents, ImportOptions $options): void
    {
        self::stash($runId, $contents);
        self::enqueue($shopId, $runId, $options);
    }

    /** @return array<string, mixed>|null */
    public static function result(string $runId): ?array
    {
        $result = Cache::get(self::RESULT_KEY.$runId);

        return is_array($result) ? $result : null;
    }

    /** @param array<string, mixed> $payload */
    private static function putResult(string $runId, array $payload): void
    {
        Cache::put(self::RESULT_KEY.$runId, $payload, now()->addMinutes(self::TTL_MINUTES));
    }

    public function failed(Throwable $e): void
    {
        self::putResult($this->runId, [
            'state' => self::STATE_FAILED,
            'message' => $e->getMessage(),
        ]);
    }
}
