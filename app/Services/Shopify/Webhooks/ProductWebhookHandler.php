<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\Product;
use App\Models\WebhookEvent;
use App\Services\Products\ProductSourceFactory;
use App\Services\Products\ProductUpserter;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the local product cache fresh from products/create|update|delete webhooks.
 *
 * Invoked by ProcessShopifyWebhookJob with the Tenant ALREADY bound (its
 * TenantContext middleware + the shop-mismatch assertion guarantee we act for the
 * right shop). So this handler freely uses tenant-scoped models — never passes
 * shop_id by hand, never bypasses the global scope.
 *
 * create/update: re-FETCH the product from the source (the webhook payload is a
 * partial/snapshot — fetching the canonical record is simpler + always complete)
 * and run the SAME ProductUpserter the bulk import uses.
 * delete: mark the local row STATUS_UNLISTED. Plans are KEPT (a re-published or
 * re-created product re-uses them) — soft-remove, never hard-delete.
 *
 * Idempotency: the upsert is keyed by external id, and the job's processed_at +
 * the WebhookEvent dedupe collapse duplicate deliveries — a re-fired webhook is a
 * harmless no-op.
 */
final class ProductWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly ProductUpserter $upserter) {}

    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return;
        }

        $payload = (array) $event->raw_payload;
        $externalId = $this->externalIdFrom($payload);
        if ($externalId === '') {
            Log::warning('shopify.product_webhook.missing_id', [
                'shop_id' => $shop->id,
                'topic' => $event->topic,
            ]);

            return;
        }

        if ((string) $event->topic === 'products/delete') {
            // Delete carries no fetchable record — match the local row by the
            // NUMERIC id (the cache stores the bare id, not the GID).
            $this->markUnlisted($this->numericId($externalId));

            return;
        }

        // products/create | products/update → fetch the canonical product + upsert.
        $source = ProductSourceFactory::for($shop);
        $data = $source->fetchOne($shop, $externalId);

        if ($data === null) {
            // Race: created then deleted before we fetched. Treat as a soft-remove.
            $this->markUnlisted($externalId);

            return;
        }

        $this->upserter->upsert($data, $source->platform());
    }

    /**
     * Mark the cached product as soft-removed upstream. Tenant-scoped (the global
     * scope constrains by shop_id); plans on the product are intentionally KEPT.
     */
    private function markUnlisted(string $externalId): void
    {
        Product::query()
            ->where('source', Product::SOURCE_SHOPIFY)
            ->where('external_id', $externalId)
            ->update(['status' => Product::STATUS_UNLISTED, 'synced_at' => now()]);
    }

    /**
     * Pull the upstream product id from the webhook payload. Prefer the GID
     * (admin_graphql_api_id) so fetchOne resolves it directly; fall back to the
     * numeric id (the source normalises both).
     *
     * @param  array<string, mixed>  $payload
     */
    private function externalIdFrom(array $payload): string
    {
        $gid = (string) ($payload['admin_graphql_api_id'] ?? '');
        if ($gid !== '') {
            return $gid;
        }

        $id = $payload['id'] ?? '';

        return $id === '' ? '' : (string) $id;
    }

    /** "gid://shopify/Product/8001" → "8001"; a bare id passes through unchanged. */
    private function numericId(string $externalId): string
    {
        $slash = strrpos($externalId, '/');

        return $slash === false ? $externalId : substr($externalId, $slash + 1);
    }
}
