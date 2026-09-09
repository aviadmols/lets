<?php

namespace App\Domain\Refunds\Jobs;

use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Run one refund request's legs, off the request thread.
 *
 * A refund talks to PayPlus, then to Shopify or WooCommerce, then queues a
 * document — three other people's HTTP endpoints. Holding a merchant's browser
 * open across all of them is how a drawer times out halfway and a merchant
 * clicks again, so the drawer opens the request, dispatches this, and watches
 * the row.
 *
 * ONE ATTEMPT. A queue-level retry would re-enter a request whose money may
 * already be moving, and `RefundOrchestrator::run()` is resumable but not
 * free — a second worker on the same request would race the first through the
 * money leg. ShouldBeUnique keeps two dispatches apart; `tries = 1` keeps the
 * queue itself from producing the second one. A run that dies mid-flight leaves
 * the row exactly where it stopped, which is the whole reason the row exists;
 * the merchant resumes it from the drawer.
 *
 * shop_id travels EXPLICITLY and TenantContext binds it (CLAUDE.md).
 */
final class RunRefundRequestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** Money moves here — the charges lane, beside every other money-out job. */
    public const QUEUE = TenantContext::QUEUE_CHARGES;

    /** Long enough to outlast three external APIs and their timeouts. */
    public int $uniqueFor = 1800;

    /** @see the class doc — one attempt, deliberately. */
    public int $tries = 1;

    public function __construct(
        public readonly int $shopId,
        public readonly int $requestId,
        /** True for the merchant's "try the store again" button: money is done. */
        public readonly bool $storeOnly = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:refundreq:%d', $this->shopId, $this->requestId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(RefundOrchestrator $orchestrator): void
    {
        $shop = Shop::query()->find($this->shopId);
        $request = RefundRequest::query()->find($this->requestId);

        if (! $shop instanceof Shop || $request === null) {
            Log::warning('refunds.job.missing', [
                'shop_id' => $this->shopId,
                'request_id' => $this->requestId,
            ]);

            return;
        }

        $this->storeOnly
            ? $orchestrator->retryStore($shop, $request)
            : $orchestrator->run($shop, $request);
    }
}
