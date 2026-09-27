<?php

namespace App\Domain\Upsell\Verification;

/**
 * The parent order + the shopper who placed it, as the SERVER established them —
 * never as a request claimed them.
 *
 * A one-click upsell charges a saved card, so the only acceptable source for
 * "whose card" is a fact the platform verified and bound to the order the shopper
 * just completed: Shopify's own record of the order's customer, or the ledger row
 * LETS wrote when that WooCommerce order was paid. Every accept URL is minted
 * from one of these, and every WooCommerce accept is rebuilt from one.
 */
final class VerifiedPurchase
{
    public function __construct(
        /** The canonical parent order id (numeric Shopify id / WooCommerce order id). */
        public readonly string $parentOrderId,
        /** The vault lookup key: the customer the order belongs to. Never empty. */
        public readonly string $customerRef,
        public readonly ?string $customerEmail = null,
    ) {}
}
