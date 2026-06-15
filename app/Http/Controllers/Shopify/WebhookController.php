<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\Shopify\ProcessShopifyWebhookJob;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONE platform webhook endpoint for ALL shops + ALL topics. HMAC has already
 * been verified by VerifyShopifyWebhook middleware (raw-body, fail-closed). Here
 * we: resolve the Shop by X-Shopify-Shop-Domain (a routing HINT, not auth),
 * dedupe by webhook_id (at-least-once delivery), persist the raw payload, enqueue
 * a tenant-bound job, and return 202 in <500ms. NO charging/syncing inline —
 * Shopify times out at ~5s and a slow handler creates duplicate deliveries.
 *
 * The {topic?} path param is a convenience for routing/registration; the
 * authoritative topic always comes from the X-Shopify-Topic header.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, ?string $topic = null): JsonResponse
    {
        $headers = (array) config('shopify.webhook_headers');

        $topic = (string) $request->header($headers['topic'] ?? 'X-Shopify-Topic', (string) $topic);
        $webhookId = (string) $request->header($headers['webhook_id'] ?? 'X-Shopify-Webhook-Id', '');
        $shopDomain = (string) $request->header($headers['shop_domain'] ?? 'X-Shopify-Shop-Domain', '');

        // Resolve the shop row from the routing-hint header (HMAC already proved
        // Shopify sent it). Unknown shop ⇒ 202 + log; never 500 — uninstalled /
        // never-installed shops still emit webhooks (e.g. a late retry).
        $shop = Shop::query()->where('shopify_domain', $shopDomain)->first();
        if ($shop === null) {
            Log::info('shopify.webhook.unknown_shop', ['shop_domain' => $shopDomain, 'topic' => $topic]);

            return response()->json(['status' => 'unknown_shop'], Response::HTTP_ACCEPTED);
        }

        $payload = (array) $request->json()->all();

        // Dedupe key scoped by shop — a replay for Shop A can never collide with B.
        // firstOrCreate is atomic against the unique index; a second delivery with
        // the same (shop_id, source, webhook_id, topic) returns the existing row.
        $event = WebhookEvent::query()->firstOrCreate(
            [
                'shop_id' => $shop->id,
                'source' => WebhookEvent::SOURCE_SHOPIFY,
                'webhook_id' => $webhookId !== '' ? $webhookId : null,
                'topic' => $topic,
            ],
            [
                'shopify_id' => data_get($payload, 'id'),
                'shop_domain' => $shopDomain,
                'raw_payload' => $payload,
                'headers' => $this->auditHeaders($request),
                'hmac_valid' => true,
                'received_at' => now(),
            ],
        );

        if (! $event->wasRecentlyCreated) {
            // Shopify retried; we already have it. Re-enqueue only if not yet
            // processed (covers a crash between persist and job pickup).
            if (! $event->isProcessed()) {
                ProcessShopifyWebhookJob::dispatch($shop->id, $event->id)->onQueue(TenantContext::QUEUE_WEBHOOKS);
            }

            return response()->json(['status' => 'duplicate_accepted', 'event_id' => $event->id], Response::HTTP_ACCEPTED);
        }

        ProcessShopifyWebhookJob::dispatch($shop->id, $event->id)->onQueue(TenantContext::QUEUE_WEBHOOKS);

        return response()->json(['status' => 'accepted', 'event_id' => $event->id], Response::HTTP_ACCEPTED);
    }

    /** @return array<string, string|null> Allowlisted, masked audit headers. */
    private function auditHeaders(Request $request): array
    {
        return [
            'x-shopify-topic' => $request->header('x-shopify-topic'),
            'x-shopify-hmac-sha256' => $request->header('x-shopify-hmac-sha256') !== null ? '[present]' : null,
            'x-shopify-webhook-id' => $request->header('x-shopify-webhook-id'),
            'x-shopify-shop-domain' => $request->header('x-shopify-shop-domain'),
            'x-shopify-api-version' => $request->header('x-shopify-api-version'),
            'x-shopify-triggered-at' => $request->header('x-shopify-triggered-at'),
        ];
    }
}
