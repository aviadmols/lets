<?php

namespace App\Domain\Loyalty\Credit;

use App\Models\LoyaltyAccount;
use App\Models\Shop;

/**
 * Turning points into money the shopper can spend, on whichever platform they
 * shop. The two implementations differ in kind, not in wrapping: Shopify has a
 * native store-credit account we top up; WooCommerce has no such thing, so the
 * honest equivalent is a personal, single-use coupon for the same amount.
 *
 * The contract is deliberately narrow: issue the credit, or throw. The caller
 * (RedeemService) only deducts points once this returns — a failure must leave
 * the customer's balance exactly where it was.
 */
interface CreditIssuer
{
    /**
     * Issue $amount of credit to this member. Returns a display token (a coupon
     * code) when the platform gives the shopper something to copy, or null when
     * the credit simply appears on their account.
     *
     * @throws \RuntimeException when the platform refused or could not be reached
     */
    public function issue(Shop $shop, LoyaltyAccount $account, float $amount, string $currency): ?string;

    /** Can this shop issue credit at all right now (connected, scoped)? */
    public function available(Shop $shop): bool;
}
