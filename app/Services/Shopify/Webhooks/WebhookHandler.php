<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;

/**
 * A topic handler. Invoked by ProcessShopifyWebhookJob with the Tenant ALREADY
 * bound (the job's TenantContext middleware did that) — so a handler may freely
 * use tenant-scoped models. The handler must be idempotent: the job's
 * processed_at guard + the WebhookEvent dedupe key are the first lines of
 * defense, but a handler that creates Shopify state must also guard on its own
 * unique key (e.g. shopify_order_id / idempotency key).
 */
interface WebhookHandler
{
    public function handle(WebhookEvent $event): void;
}
