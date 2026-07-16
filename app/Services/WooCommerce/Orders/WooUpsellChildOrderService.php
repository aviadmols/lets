<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Records an ACCEPTED post-purchase upsell on the customer's WooCommerce order (W11 P4; W18b).
 *
 * PREFERRED (W18b): the upsell product is ADDED AS A LINE ITEM to the customer's EXISTING order
 * (the one they just checked out), so the merchant sees ONE order — not a separate child order.
 * WooCommerce recalculates the order total to include the added line. Only if that fails (or there
 * is no parent order id) do we fall back to a linked, paid CHILD order, so the money is never lost.
 *
 * Money law: called ONLY AFTER UpsellChargeService::accept has already charged the saved PayPlus
 * token and recorded the SUCCEEDED ledger row. This is a RECORD of that money, never a second
 * charge — a failure here never unwinds the charge (the ledger stands); we log + return null so the
 * caller can flag a reconcile. The upsell charge is idempotent on its deterministic ledger key, so a
 * double-click never reaches here twice with a real charge.
 *
 * Tenant law: the per-shop WC client is resolved via WooClientFactory::for($shop); the caller has
 * bound the tenant from the HMAC-verified shop.
 */
final class WooUpsellChildOrderService
{
    // === CONSTANTS ===
    public const META_PARENT_ORDER_ID = 'lets_parent_order_id';
    public const META_ORDER_ROLE = 'lets_order_role';
    public const META_UPSELL_OFFER_ID = 'lets_upsell_offer_id';
    public const ROLE_UPSELL_CHILD = 'upsell_child';

    private const STATUS_COMPLETED = 'completed';

    /**
     * Record the accepted upsell. Prefers adding the item to the parent order; returns the order id
     * the item landed on (the parent's, or a new child's), or null when the shop isn't WC-connected
     * or WC rejected every path (the money already moved regardless).
     */
    public function create(
        Shop $shop,
        UpsellFlowOffer $offer,
        string $parentOrderId,
        float $amount,
        string $currency,
        ?string $customerEmail,
    ): ?string {
        if (! $shop->hasWooConnection()) {
            // Decoupled: the engine still charged + recorded; we just can't record the WC order for
            // an unconnected store. Safe no-op.
            return null;
        }

        // PREFERRED: add the item to the shopper's existing order.
        if (trim($parentOrderId) !== '') {
            $attached = $this->addToParentOrder($shop, $offer, $parentOrderId, $amount);
            if ($attached !== null) {
                return $attached;
            }
            Log::warning('woocommerce.upsell.attach_fell_back_to_child', [
                'shop_id' => $shop->getKey(), 'parent_order_id' => $parentOrderId, 'offer_id' => $offer->getKey(),
            ]);
        }

        // FALLBACK: a linked, paid child order so the charge is never lost.
        return $this->createChildOrder($shop, $offer, $parentOrderId, $amount, $currency, $customerEmail);
    }

    /**
     * Add the upsell product as a LINE ITEM on the parent order. WooCommerce recalculates the order
     * total (the REST controller calls calculate_totals when line_items change). A line with no `id`
     * is ADDED; existing lines are untouched. Also drops a merchant-visible note documenting the
     * separate token charge. Returns the parent order id, or null on failure (→ child fallback).
     */
    private function addToParentOrder(Shop $shop, UpsellFlowOffer $offer, string $parentOrderId, float $amount): ?string
    {
        try {
            $client = WooClientFactory::for($shop);

            $order = $client->updateOrder($parentOrderId, [
                // New line item (no `id`) → WooCommerce adds it + recalculates the order total.
                // `total` pins the server-computed charged price; the product's own price becomes the
                // subtotal, so any offer discount shows just like a normal discounted line.
                'line_items' => [$this->lineItem($offer, $amount)],
                'meta_data' => [
                    ['key' => self::META_UPSELL_OFFER_ID, 'value' => (string) $offer->getKey()],
                ],
            ]);

            $orderId = (string) ($order['id'] ?? '');
            if ($orderId === '') {
                return null;
            }

            // Document the separate one-click token charge on the order (merchant-visible note).
            $client->addOrderNote(
                $parentOrderId,
                sprintf(
                    'LETS one-click upsell added: %s — %s charged to the saved card (no card re-entry).',
                    (string) ($offer->offer_title ?? __('upsell.offer_default_title')),
                    number_format(round($amount, 2), 2, '.', ''),
                ),
                false,
            );

            return $orderId;
        } catch (\Throwable $e) {
            Log::warning('woocommerce.upsell.attach_failed', [
                'shop_id' => $shop->getKey(), 'parent_order_id' => $parentOrderId,
                'offer_id' => $offer->getKey(), 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create the paid child order (the fallback when the parent can't be edited). Returns the new WC
     * order id, or null when WC rejected the call (the money already moved regardless).
     */
    private function createChildOrder(
        Shop $shop,
        UpsellFlowOffer $offer,
        string $parentOrderId,
        float $amount,
        string $currency,
        ?string $customerEmail,
    ): ?string {
        try {
            $order = WooClientFactory::for($shop)->createOrder([
                'status' => self::STATUS_COMPLETED,   // a fulfillable, paid add-on order
                'set_paid' => true,                   // the money already moved through PayPlus
                'currency' => $currency,
                'billing' => array_filter([
                    'email' => (string) ($customerEmail ?? ''),
                ], static fn ($v): bool => $v !== ''),
                'line_items' => [$this->lineItem($offer, $amount)],
                'meta_data' => [
                    ['key' => self::META_ORDER_ROLE, 'value' => self::ROLE_UPSELL_CHILD],
                    ['key' => self::META_PARENT_ORDER_ID, 'value' => $parentOrderId],
                    ['key' => self::META_UPSELL_OFFER_ID, 'value' => (string) $offer->getKey()],
                ],
            ]);

            $orderId = (string) ($order['id'] ?? '');

            return $orderId !== '' ? $orderId : null;
        } catch (\Throwable $e) {
            // The charge SUCCEEDED but the child order failed — never lose it silently.
            Log::error('woocommerce.upsell.child_order_failed', [
                'shop_id' => $shop->getKey(),
                'parent_order_id' => $parentOrderId,
                'offer_id' => $offer->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The upsell WC line item. Links the REAL product (raw numeric WC id) so WooCommerce decrements
     * stock, shows the product, and can fulfil it. `total` pins the server-computed discounted price,
     * so the product's own price can't override the money that was actually charged. A non-numeric
     * gid yields a name-only line — never a wrong product.
     *
     * @return array<string, mixed>
     */
    private function lineItem(UpsellFlowOffer $offer, float $amount): array
    {
        $lineItem = [
            'name' => (string) ($offer->offer_title ?? __('upsell.offer_default_title')),
            'quantity' => 1,
            'total' => number_format(round($amount, 2), 2, '.', ''),
        ];
        if (($productId = $this->numericId($offer->offer_product_gid)) > 0) {
            $lineItem['product_id'] = $productId;
        }
        if (($variationId = $this->numericId($offer->offer_variant_gid)) > 0) {
            $lineItem['variation_id'] = $variationId;
        }

        return $lineItem;
    }

    /** A positive WooCommerce numeric id from a stored identifier, or 0 if it isn't one. */
    private function numericId(?string $identifier): int
    {
        $identifier = trim((string) $identifier);

        return ctype_digit($identifier) ? (int) $identifier : 0;
    }
}
