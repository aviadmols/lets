<?php

namespace App\Jobs\Shopify;

use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Registers (idempotently) all webhook topics for ONE shop to the single platform
 * endpoint. The multi-tenant refactor of the reference engine's single-tenant
 * RegisterShopifyWebhooksCommand (which registered to one global address with one
 * global token). Here the job carries shop_id EXPLICITLY, binds the tenant, and
 * uses that shop's own token via ShopifyClientFactory::for($shop).
 *
 * Idempotent: skips a topic already subscribed to the platform address — Shopify
 * also dedupes identical (topic,address) subscriptions, so a re-run on reinstall
 * never creates duplicates.
 */
final class RegisterShopifyWebhooksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_WEBHOOKS;

    public function __construct(public readonly int $shopId)
    {
        $this->onQueue(self::QUEUE);
    }

    /** Bind the tenant for the job lifetime; clears in finally (worker-safe). */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(): void
    {
        $shop = Shop::query()->whereKey($this->shopId)->first();
        if ($shop === null || ! $shop->isLive()) {
            return;
        }

        $address = (string) config('shopify.webhook_address');
        $topics = (array) config('shopify.webhook_topics');
        $client = ShopifyClientFactory::for($shop);

        foreach ($topics as $topic) {
            try {
                // Idempotent: REST list returns existing (topic,address) subs.
                if ($client->listWebhooks($topic, $address) !== []) {
                    continue;
                }
                $client->webhookSubscriptionCreate($topic, $address);
            } catch (\Throwable $e) {
                Log::warning('shopify.webhook.register_failed', [
                    'shop_id' => $this->shopId,
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
                // Continue with the remaining topics; a transient failure on one
                // topic must not block the others. The job may be retried.
            }
        }
    }
}
