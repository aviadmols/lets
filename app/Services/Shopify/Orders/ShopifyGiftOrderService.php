<?php

namespace App\Services\Shopify\Orders;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;

/**
 * Creates the FREE gift order in Shopify.
 *
 * Same shape of truth as the WooCommerce sibling: the line carries the product's
 * FULL price and a discount code covers all of it, so the merchant's reports show
 * the gift's value instead of a ₪0 line that looks like nothing was given.
 *
 * NO `transactions` block, deliberately. ShopifyOrderCreator sends one on recurring
 * orders to show real PayPlus money as Paid — but after the discount this order's
 * total is zero, and a zero-amount sale transaction is not a thing Shopify accepts.
 * `financial_status: paid` states the truth on its own: nothing is owed.
 *
 * No money moved, so no ledger row and no accounting document. The order carries no
 * plan attribute, so the plan/ledger document pipeline never sees it either.
 */
final class ShopifyGiftOrderService
{
    // === CONSTANTS ===
    /** Nothing is owed on a gift; the order is settled the moment it exists. */
    private const FINANCIAL_STATUS = 'paid';

    /** A shipped gift really does leave the shelf. */
    private const INVENTORY_BEHAVIOUR = 'decrement_obeying_policy';

    /** The discount that zeroes the line. Shopify prints this code on the order. */
    private const DISCOUNT_CODE = 'GIFT';
    private const DISCOUNT_TYPE = 'fixed_amount';

    private const SHIPPING_CODE = 'lets_gift';

    private const ROLE_ATTRIBUTE = 'lets_order_role';
    private const CAMPAIGN_ATTRIBUTE = 'lets_gift_campaign_id';
    /**
     * Public: GiftOrderReconciler searches Shopify on exactly this attribute when
     * an attempt's outcome is unknown. Without it stamped, a gift that landed
     * during an outage could never be traced back to its recipient.
     */
    public const RECIPIENT_ATTRIBUTE = 'lets_gift_recipient_id';
    public const ROLE_GIFT = 'gift_order';

    /** The tag used when the config names none — also the reconciler's search. */
    public const DEFAULT_TAG = 'lets-gift';

    /**
     * @return string|null the created Shopify order id, or null when Shopify refused
     */
    public function create(
        Shop $shop,
        GiftCampaign $campaign,
        GiftRecipient $recipient,
        GiftShippingAddress $address,
    ): ?string {
        if (! $shop->hasShopifyConnection()) {
            return null;
        }

        $variantId = (int) ($campaign->variant?->external_variant_id ?? 0);
        if ($variantId <= 0) {
            return null; // Shopify sells variants; without one there is no line to create.
        }

        $price = number_format((float) $campaign->unit_price, 2, '.', '');

        $payload = [
            'email' => (string) ($recipient->customer_email ?? ''),
            'currency' => (string) ($recipient->currency ?: $campaign->currency),
            'source_name' => (string) config('shopify.order_source_name', 'payplus-subscriptions'),
            'tags' => (string) (config('shopify.tags.gift_order') ?: self::DEFAULT_TAG),
            // The merchant is giving a present, not announcing a purchase.
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'financial_status' => self::FINANCIAL_STATUS,
            'inventory_behaviour' => self::INVENTORY_BEHAVIOUR,
            'line_items' => [[
                'variant_id' => $variantId,
                'quantity' => 1,
                'price' => $price,          // the gift's real value
                'requires_shipping' => true,
            ]],
            'discount_codes' => [[
                'code' => self::DISCOUNT_CODE,
                'amount' => $price,          // …covered in full
                'type' => self::DISCOUNT_TYPE,
            ]],
            'shipping_lines' => [[
                'title' => (string) ($campaign->shipping_label ?: __('gifts.default_shipping_label')),
                'price' => '0.00',
                'code' => self::SHIPPING_CODE,
            ]],
            'shipping_address' => $address->toShopifyBlock(),
            'note_attributes' => [
                ['name' => self::ROLE_ATTRIBUTE, 'value' => self::ROLE_GIFT],
                ['name' => self::CAMPAIGN_ATTRIBUTE, 'value' => (string) $campaign->getKey()],
                ['name' => self::RECIPIENT_ATTRIBUTE, 'value' => (string) $recipient->getKey()],
            ],
        ];

        $order = ShopifyClientFactory::for($shop)->createOrder($payload);

        $orderId = (string) ($order['id'] ?? '');

        return $orderId !== '' ? $orderId : null;
    }
}
