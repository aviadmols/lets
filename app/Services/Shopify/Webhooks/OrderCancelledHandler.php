<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;
use App\Support\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Handles orders/cancelled. A cancelled parent order should stop its plan's
 * future charges (the money decision belongs to laravel-backend's state machine).
 * We resolve the shop + order id and emit a tenant-bound event for the backend's
 * cancellation reconciler. Port of the reference ShopifyOrderCancellationListener
 * seam — Shopify SHAPE here, plan/ledger transitions there.
 */
final class OrderCancelledHandler implements WebhookHandler
{
    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return;
        }

        $orderId = (string) (data_get((array) $event->raw_payload, 'id') ?? '');

        Log::info('shopify.order_cancelled', ['shop_id' => $shop->id, 'order_id' => $orderId]);

        // TODO(laravel-backend): subscribe a listener that pauses/cancels the plan
        //   linked to $orderId and writes the ledger + Timeline events.
        Event::dispatch('shopify.order.cancelled', [[
            'shop_id' => $shop->id,
            'order_id' => $orderId,
            'webhook_event_id' => $event->id,
        ]]);
    }
}
