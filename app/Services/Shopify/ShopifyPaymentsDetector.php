<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Asks SHOPIFY whether this store sells through Shopify Payments, and records
 * the answer on the shop (shopify_payments_status). Two rules govern this:
 *
 *   1. UNKNOWN IS AN ANSWER. The query needs an API the app may not be granted
 *      (and a custom-distribution app on a dev store may not have it at all).
 *      Any error — access denied, transport, a shape we do not recognise —
 *      leaves the status `unknown`, never `inactive`. Screens are hidden on a
 *      CONFIRMED active, never on a failed lookup.
 *   2. It never overrides the merchant. Auto-tagging onto the Shopify-Payments
 *      rail happens ONLY for a store that has no PayPlus credentials and has not
 *      been moved off the default rail — so a merchant's own choice in
 *      Settings → Billing always wins, and re-detection cannot undo it.
 */
final class ShopifyPaymentsDetector
{
    // === CONSTANTS ===
    /** The one field that answers the question; `id` alone proves an account exists. */
    private const QUERY = <<<'GQL'
    query letsShopifyPaymentsAccount {
      shopifyPaymentsAccount { id activated }
    }
    GQL;

    /**
     * Detect + persist. Returns the resulting status (one of Shop::SHOPIFY_PAYMENTS_*).
     * Safe to call repeatedly; it is a read at Shopify plus one local write.
     */
    public function detect(Shop $shop): string
    {
        if (! $shop->hasShopifyConnection()) {
            return $shop->shopifyPaymentsStatus();
        }

        try {
            $body = ShopifyClientFactory::for($shop)->graphql(self::QUERY);
        } catch (\Throwable $e) {
            // Not granted / transport / anything else → we do NOT know. Leave the
            // stored status alone so a previous confirmed answer is not erased.
            Log::info('shopify.payments.detect_unavailable', [
                'shop_id' => $shop->getKey(), 'error' => $e->getMessage(),
            ]);

            return $shop->shopifyPaymentsStatus();
        }

        // A GraphQL error payload (e.g. ACCESS_DENIED) is also "unknown", not "no".
        if (($body['errors'] ?? []) !== []) {
            Log::info('shopify.payments.detect_denied', ['shop_id' => $shop->getKey()]);

            return $shop->shopifyPaymentsStatus();
        }

        $account = data_get($body, 'data.shopifyPaymentsAccount');
        $status = match (true) {
            ! is_array($account) => Shop::SHOPIFY_PAYMENTS_INACTIVE,   // Shopify answered: no account
            ($account['activated'] ?? true) === false => Shop::SHOPIFY_PAYMENTS_INACTIVE,
            default => Shop::SHOPIFY_PAYMENTS_ACTIVE,
        };

        $shop->forceFill([
            'shopify_payments_status' => $status,
            'shopify_payments_checked_at' => now(),
        ])->save();

        if ($status === Shop::SHOPIFY_PAYMENTS_ACTIVE) {
            $this->tagRail($shop);
        }

        return $status;
    }

    /**
     * Tag a confirmed Shopify-Payments store onto that rail — but only when there
     * is nothing to overrule: no PayPlus credentials, and the shop still on the
     * default rail. A merchant who chose an engine keeps it.
     */
    private function tagRail(Shop $shop): void
    {
        if ($shop->hasPayplusConnection() || $shop->usesShopifyPaymentsRail()) {
            return;
        }

        $shop->forceFill(['subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS])->save();

        Log::info('shopify.payments.rail_tagged', [
            'shop_id' => $shop->getKey(), 'rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
    }
}
