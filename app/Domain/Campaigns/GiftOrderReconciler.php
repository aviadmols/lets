<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyGiftOrderService;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\Orders\WooGiftOrderService;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Did the order we could not confirm actually land?
 *
 * A store that answers 500 — or never answers — has told us nothing about whether
 * it created the order. WooCommerce and Shopify both create the order first and
 * run the merchant's hooks after, so a fatal in somebody's plugin produces exactly
 * this: a real order, and a 500 describing it.
 *
 * Guessing either way is expensive. Guess "failed" and a retry ships a second
 * package; guess "created" with no id and the merchant has a gift they cannot
 * trace. So we go and look, keyed on the recipient id every gift order is stamped
 * with. Found → the gift landed. Not found → nobody may retry it automatically;
 * that is what `unresolved` is for.
 *
 * Never throws: this runs in the failure path, and a reconciliation that blows up
 * would replace a recoverable state with a lost one.
 */
final class GiftOrderReconciler
{
    // === CONSTANTS ===
    /**
     * How far back to look. Reconciliation runs seconds after the attempt, so the
     * order — if it exists — is among the newest in the store. A deeper scan costs
     * the merchant's store more than it buys us.
     */
    public const SCAN_ORDERS = 50;

    /**
     * The search key. Each platform service owns its own stamp (the same
     * per-platform vocabulary as ROLE_GIFT), and this reads theirs rather than
     * repeating the literal — a search for a key nobody writes finds nothing, and
     * would silently report every order as missing.
     */
    private const WOO_KEY = WooGiftOrderService::META_RECIPIENT_ID;
    private const SHOPIFY_KEY = ShopifyGiftOrderService::RECIPIENT_ATTRIBUTE;

    /** The store's id of the order created for this recipient, or null. */
    public function find(Shop $shop, GiftRecipient $recipient): ?string
    {
        try {
            return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
                ? $this->inWoo($shop, $recipient)
                : $this->inShopify($shop, $recipient);
        } catch (\Throwable $e) {
            Log::warning('campaigns.gift.reconcile_failed', [
                'shop_id' => $shop->getKey(),
                'recipient_id' => $recipient->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function inWoo(Shop $shop, GiftRecipient $recipient): ?string
    {
        if (! $shop->hasWooConnection()) {
            return null;
        }

        $needle = (string) $recipient->getKey();

        foreach (WooClientFactory::for($shop)->fetchRecentOrders(self::SCAN_ORDERS) as $order) {
            foreach ((array) ($order['meta_data'] ?? []) as $meta) {
                if (($meta['key'] ?? '') === self::WOO_KEY && (string) ($meta['value'] ?? '') === $needle) {
                    return (string) ($order['id'] ?? '') ?: null;
                }
            }
        }

        return null;
    }

    /**
     * Shopify carries the same stamp as a note attribute. The tag narrows the scan
     * to gift orders so a busy store's recent page is still ours to search.
     */
    private function inShopify(Shop $shop, GiftRecipient $recipient): ?string
    {
        if (! $shop->hasShopifyConnection()) {
            return null;
        }

        $tag = (string) (config('shopify.tags.gift_order') ?: ShopifyGiftOrderService::DEFAULT_TAG);
        $needle = (string) $recipient->getKey();

        $body = ShopifyClientFactory::for($shop)->graphql(
            'query giftOrders($query: String!, $first: Int!) {
                orders(first: $first, query: $query, sortKey: CREATED_AT, reverse: true) {
                    nodes { id customAttributes { key value } }
                }
            }',
            ['query' => 'tag:'.$tag, 'first' => self::SCAN_ORDERS],
        );

        foreach ((array) data_get($body, 'data.orders.nodes', []) as $node) {
            foreach ((array) ($node['customAttributes'] ?? []) as $attribute) {
                if (($attribute['key'] ?? '') === self::SHOPIFY_KEY && (string) ($attribute['value'] ?? '') === $needle) {
                    return (string) ($node['id'] ?? '') ?: null;
                }
            }
        }

        return null;
    }
}
