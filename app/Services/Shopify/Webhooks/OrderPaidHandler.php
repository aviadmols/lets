<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;
use App\Support\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Handles orders/paid + orders/create — the entry point where a checkout that
 * carried an installment/recurring intent gets its PayPlus token captured and its
 * plan activated.
 *
 * OWNERSHIP BOUNDARY: this agent owns the Shopify SHAPE, not the MONEY. The actual
 * token capture (the reference's PayPlusCustomerTokenResolver 4-strategy chain)
 * and plan activation (PlanActivationService) belong to laravel-backend. Here we
 * verify the event, extract the order identity, and emit a tenant-bound domain
 * event that laravel-backend's listener consumes. We never call PayPlus.
 *
 * Idempotency: the WebhookEvent dedupe key + the job's processed_at guard collapse
 * duplicate deliveries; downstream activation must ALSO guard on the plan's
 * idempotency key so "the webhook fired twice" never double-activates.
 */
final class OrderPaidHandler implements WebhookHandler
{
    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return;
        }

        $payload = (array) $event->raw_payload;
        $orderId = (string) (data_get($payload, 'id') ?? '');

        Log::info('shopify.order_paid', [
            'shop_id' => $shop->id,
            'topic' => $event->topic,
            'order_id' => $orderId,
        ]);

        // Seam to laravel-backend: it owns token capture + plan activation. We emit
        // a string-keyed event (no hard class dependency on a backend class that
        // may not exist yet in this phase) so the listener can be wired without a
        // compile-time coupling. The tenant is bound; the listener reads shop_id
        // from the event payload to stay explicit.
        //
        // TODO(laravel-backend): subscribe a listener to 'shopify.order.paid' that
        //   runs PayPlusCustomerTokenResolver → PlanActivationService → (on first
        //   installment success) ShopifyOrderStrategy::materializeForContext(deposit).
        Event::dispatch('shopify.order.paid', [[
            'shop_id' => $shop->id,
            'topic' => $event->topic,
            'order_id' => $orderId,
            'webhook_event_id' => $event->id,
            'payload' => $payload,
        ]]);
    }
}
