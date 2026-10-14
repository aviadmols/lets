<?php

namespace App\Domain\OutboundWebhooks\Jobs;

use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\WebhookDeliverer;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one outbound webhook. The shop travels explicitly and TenantContext
 * binds it; the retry schedule is the deliverer's (it re-dispatches this job
 * with a delay), so the queue's own retries stay at one — a timed-out POST must
 * not be re-sent by the queue AND by the backoff.
 *
 * On the low-priority `sync` queue: a slow merchant endpoint may wait behind
 * charges, never in front of them.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_SYNC;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $shopId,
        public readonly int $deliveryId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(WebhookDeliverer $deliverer): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && ! $delivery->isFinished()) {
            $deliverer->attempt($delivery);
        }
    }
}
