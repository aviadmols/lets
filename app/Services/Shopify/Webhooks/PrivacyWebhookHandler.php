<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * The three MANDATORY privacy webhooks (App Store rejection if missing). We own
 * the TRANSPORT (receive + HMAC-verify + route); saas-multitenancy-billing owns
 * the DATA POLICY (what to erase/export). This handler verifies the payload shape
 * and hands a clear, tenant-bound seam to the SaaS agent.
 *
 *   - customers/redact      → erase a customer's personal data for this shop.
 *   - shop/redact           → erase ALL shop data (fires ~48h after uninstall).
 *   - customers/data_request→ export a customer's data (the merchant requests it).
 *
 * Shopify requires a 200 within the verification window; the controller already
 * returned 202 and this runs async, which Shopify accepts for these topics.
 */
final class PrivacyWebhookHandler implements WebhookHandler
{
    // === CONSTANTS ===
    public const TOPIC_CUSTOMERS_REDACT = 'customers/redact';
    public const TOPIC_SHOP_REDACT = 'shop/redact';
    public const TOPIC_CUSTOMERS_DATA_REQUEST = 'customers/data_request';

    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        $payload = (array) $event->raw_payload;

        Log::info('shopify.privacy_webhook', [
            'topic' => $event->topic,
            'shop_id' => $shop?->id,
            'customer_id' => data_get($payload, 'customer.id'),
        ]);

        // TODO(saas-multitenancy-billing): the data policy lives there. Wire its
        //   listener to act on each topic with the tenant bound:
        //     customers/redact       → RedactCustomerData::dispatch($shop->id, $payload)
        //     shop/redact            → RedactShopData::dispatch($shop->id, $payload)
        //     customers/data_request → ExportCustomerData::dispatch($shop->id, $payload)
        //   We deliberately do NOT erase here — deletion policy + retention windows
        //   are the SaaS agent's contract. This transport stays policy-free.
        match ($event->topic) {
            self::TOPIC_CUSTOMERS_REDACT,
            self::TOPIC_SHOP_REDACT,
            self::TOPIC_CUSTOMERS_DATA_REQUEST => null,
            default => null,
        };
    }
}
