<?php

namespace App\Services\Shopify\Webhooks;

use App\Jobs\Privacy\ExportCustomerData;
use App\Jobs\Privacy\RedactCustomerData;
use App\Jobs\Privacy\RedactShopData;
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
            // NOTE: the raw customer id is logged by the transport for ROUTING
            // audit only; the data-policy jobs below never log PII.
            'customer_id' => data_get($payload, 'customer.id'),
        ]);

        if ($shop === null) {
            return; // job binds the tenant; defensive guard for a malformed dispatch.
        }

        // The DATA POLICY. Each topic dispatches a tenant-scoped, idempotent job
        // carrying shop_id EXPLICITLY (never inferred). The job re-binds the tenant
        // in handle() (jobs don't inherit request tenant) and acts under the
        // BelongsToShop scope, so it can only ever touch THIS shop's data.
        match ($event->topic) {
            self::TOPIC_CUSTOMERS_REDACT => RedactCustomerData::dispatch($shop->id, $payload),
            self::TOPIC_SHOP_REDACT => RedactShopData::dispatch($shop->id, $payload),
            self::TOPIC_CUSTOMERS_DATA_REQUEST => ExportCustomerData::dispatch($shop->id, $payload),
            default => null,
        };
    }
}
