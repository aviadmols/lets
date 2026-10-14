<?php

namespace App\Domain\OutboundWebhooks\Listeners;

use App\Domain\OutboundWebhooks\WebhookPayload;
use App\Domain\OutboundWebhooks\WebhookPublisher;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Events\LedgerRowSucceeded;
use App\Models\Shop;

/**
 * Every PayPlus-side success passes Ledger::transition exactly once — a store
 * checkout, a deposit, an upsell, a recurring cycle, an installment — so this one
 * listener announces all of them: `order.paid` for a new purchase (Shopify only —
 * see below), `recurring_charge.succeeded` for a cycle of an existing plan
 * (WebhookTopic decides which). An observer: the publisher waits for the commit and swallows
 * its own failures, so a merchant URL can never fail a charge.
 */
final class PublishLedgerSucceededWebhook
{
    public function __construct(private readonly WebhookPublisher $publisher) {}

    public function handle(LedgerRowSucceeded $event): void
    {
        $topic = WebhookTopic::forSucceededContext((string) $event->row->charge_context);
        if ($topic === null) {
            return;
        }

        // A WooCommerce store reports EVERY order itself (OrderWebhookController),
        // PayPlus ones included and with their full lines — announcing it here too
        // would send the same order twice. Its cycles still come from the ledger.
        if ($topic === WebhookTopic::ORDER_PAID && self::isWooCommerce($event->shopId)) {
            return;
        }

        $this->publisher->publish(
            $event->shopId,
            $topic,
            sprintf('ledger:%d:succeeded', $event->row->getKey()),
            ['kind' => WebhookPayload::SOURCE_LEDGER, 'ledger_id' => (int) $event->row->getKey()],
        );
    }

    private static function isWooCommerce(int $shopId): bool
    {
        return Shop::query()->whereKey($shopId)->value('platform') === Shop::PLATFORM_WOOCOMMERCE;
    }
}
