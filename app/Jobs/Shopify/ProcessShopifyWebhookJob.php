<?php

namespace App\Jobs\Shopify;

use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\WebhookRouter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes ONE verified Shopify webhook, tenant-safely. The async half of the
 * "verify → respond 202 → process async" contract: the controller did the fast
 * synchronous work; here we do the real work off the request path.
 *
 *   - shop_id is carried EXPLICITLY; TenantContext middleware binds it for the job
 *     lifetime and ALWAYS clears it after (worker-context-leak safe).
 *   - processed_at is the dedupe-replay guard: a re-delivered webhook that already
 *     processed returns immediately.
 *   - The WebhookEvent is read by primary key (it is intentionally NOT
 *     BelongsToShop-scoped); we ASSERT it belongs to the bound shop before acting,
 *     so a malformed dispatch can never cross tenants.
 */
final class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_WEBHOOKS;

    public int $tries = 3;

    public function __construct(
        public readonly int $shopId,
        public readonly int $webhookEventId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** Bind the tenant for the job lifetime; clears in finally (worker-safe). */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(WebhookRouter $router): void
    {
        $event = WebhookEvent::query()->whereKey($this->webhookEventId)->first();
        if ($event === null) {
            return;
        }

        // Tenant-safety assertion: the event must belong to the bound shop.
        if ((int) $event->shop_id !== $this->shopId) {
            Log::warning('shopify.webhook.shop_mismatch', [
                'job_shop_id' => $this->shopId,
                'event_shop_id' => $event->shop_id,
                'event_id' => $event->id,
            ]);

            return;
        }

        // Dedupe-replay guard: already processed ⇒ no-op (at-least-once delivery).
        if ($event->isProcessed()) {
            return;
        }

        try {
            $router->dispatch($event);
            $event->markProcessed();
        } catch (\Throwable $e) {
            Log::error('shopify.webhook.processing_failed', [
                'shop_id' => $this->shopId,
                'topic' => $event->topic,
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            // Re-throw so the queue retries (transient failures recover); a poison
            // message exhausts $tries and lands in failed_jobs for inspection.
            throw $e;
        }
    }
}
