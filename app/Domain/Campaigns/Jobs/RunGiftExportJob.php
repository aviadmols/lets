<?php

namespace App\Domain\Campaigns\Jobs;

use App\Domain\Campaigns\GiftExportRunner;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Walks a gift list export, a bounded slice per invocation, re-dispatching
 * itself until the file is complete.
 *
 * ShouldBeUniqueUntilProcessing, NOT ShouldBeUnique: a plain unique lock is held
 * until COMPLETION, so the re-dispatch from inside handle() would be swallowed as
 * a duplicate and the export would stall at its first slice.
 *
 * shop_id travels EXPLICITLY and TenantContext binds it for the job's lifetime.
 */
final class RunGiftExportJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** Store reads, no money: never in front of the charges queue. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** Unique-lock TTL. It guards a duplicate dispatch, not a slow run. */
    public int $uniqueFor = 3600;

    /** Safe to retry: a row is written only once, whichever attempt writes it. */
    public int $tries = 3;

    /** @var list<int> seconds between attempts. */
    public array $backoff = [10, 60];

    /** Well above one slice's clock, well under the queue's retry_after. */
    public int $timeout = 300;

    public function __construct(
        /** The tenant, carried explicitly — never inferred from global state. */
        public readonly int $shopId,
        public readonly int $runId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:gift_export:%d', $this->shopId, $this->runId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(GiftExportRunner $runner): void
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop || (int) $shop->getKey() !== $this->shopId) {
            return;
        }

        if (! $runner->advance($shop, $this->runId)) {
            self::dispatch($this->shopId, $this->runId);
        }
    }

    /** Every attempt is gone — say so on the run, not only in a log. */
    public function failed(Throwable $e): void
    {
        // TenantContext has unwound by now; bind this job's own shop explicitly.
        $shop = Shop::query()->find($this->shopId);

        if ($shop !== null) {
            Tenant::run($shop, fn () => app(GiftExportRunner::class)->markFailed($this->runId, $e));
        }
    }
}
