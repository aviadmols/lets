<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Creates the LINKED, PAID WooCommerce child order for an ACCEPTED post-purchase upsell
 * (W11 P4) — the WC analogue of ShopifyDraftOrderService::createUpsellChildOrderForCustomer.
 *
 * Money law: this is called ONLY AFTER UpsellChargeService::accept has already charged the
 * saved PayPlus token and recorded the SUCCEEDED ledger row. The WC order is a RECORD of
 * that money, never a second charge — so a failure here never unwinds the charge (the
 * ledger stands); we log + return null so the caller can flag a reconcile. Idempotency for
 * the CHARGE lives in UpsellChargeService (the deterministic upsell key), so a double-click
 * never reaches here twice with a real charge.
 *
 * Tenant law: the per-shop WC client is resolved via WooClientFactory::for($shop) (never a
 * shared singleton); the caller has bound the tenant from the HMAC-verified shop.
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
     * Create the paid child order. Returns the new WC order id, or null when the shop
     * isn't WC-connected or WC rejected the call (the money already moved regardless).
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
            // Decoupled: the engine still charged + recorded; we just can't record the
            // WC order for an unconnected store. Safe no-op.
            return null;
        }

        try {
            // Link the REAL product (raw numeric WC id, as PicksProducts stores it for Woo) so
            // WooCommerce decrements stock, shows the product, and can fulfil it. `total` still
            // pins the server-computed discounted price, so the product's own price can't
            // override the money that was actually charged. A non-numeric gid (e.g. a Shopify
            // shop mis-routed here) simply yields a name-only line — never a wrong product.
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

            $order = WooClientFactory::for($shop)->createOrder([
                'status' => self::STATUS_COMPLETED,   // a fulfillable, paid add-on order
                'set_paid' => true,                   // the money already moved through PayPlus
                'currency' => $currency,
                'billing' => array_filter([
                    'email' => (string) ($customerEmail ?? ''),
                ], static fn ($v): bool => $v !== ''),
                'line_items' => [$lineItem],
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

    /** A positive WooCommerce numeric id from a stored identifier, or 0 if it isn't one. */
    private function numericId(?string $identifier): int
    {
        $identifier = trim((string) $identifier);

        return ctype_digit($identifier) ? (int) $identifier : 0;
    }
}
