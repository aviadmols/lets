<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;

/**
 * Routes a verified, deduped WebhookEvent to its topic handler. Centralises the
 * topic→handler map so adding a topic is one line here (+ config webhook_topics +
 * shopify.app.toml). Unknown topics are a no-op (logged), not an error — Shopify
 * may send topics we registered but don't act on yet.
 */
final class WebhookRouter
{
    public function __construct(
        private readonly OrderPaidHandler $orderPaid,
        private readonly OrderCancelledHandler $orderCancelled,
        private readonly AppUninstalledHandler $appUninstalled,
        private readonly PrivacyWebhookHandler $privacy,
        private readonly ProductWebhookHandler $product,
    ) {}

    public function handlerFor(string $topic): ?WebhookHandler
    {
        return match ($topic) {
            'orders/paid', 'orders/create' => $this->orderPaid,
            'orders/cancelled' => $this->orderCancelled,
            'app/uninstalled' => $this->appUninstalled,
            'products/create',
            'products/update',
            'products/delete' => $this->product,
            'customers/redact',
            'shop/redact',
            'customers/data_request' => $this->privacy,
            // 'refunds/create' → hand to laravel-backend refund reconciler (TODO).
            default => null,
        };
    }

    public function dispatch(WebhookEvent $event): void
    {
        $this->handlerFor((string) $event->topic)?->handle($event);
    }
}
