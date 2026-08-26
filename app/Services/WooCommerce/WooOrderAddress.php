<?php

namespace App\Services\WooCommerce;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Customers\CustomerContactReader;
use App\Models\InstallmentPlan;
use App\Models\Shop;

/**
 * WHERE a subscription order ships, and who it is billed to.
 *
 * Orders LETS created carried an email, a name and a phone — and no address at
 * all. A subscription box therefore arrived in WooCommerce with nowhere to send
 * it, and the merchant filled it in by hand every cycle.
 *
 * The STORE is the authority, deliberately. The address a shopper edits in My
 * Account is the address their next box must go to, and reading it at order time
 * is what makes that true without a sync, a webhook, or a second copy to drift.
 * It is also why LETS still stores no addresses of its own (the law
 * CustomerContact already runs on): one source, read through, nothing to redact
 * later.
 *
 * Fallbacks, in order:
 *   1. The store's customer record — shipping block, then billing (the reader's
 *      own preference: shipping is where a package goes).
 *   2. The plan's OWN stored address. Imported members have one and often have
 *      no store account at all, so without this their first renewal would ship
 *      nowhere. Their address is written in the import's vocabulary (street,
 *      building number, apartment), which is mapped here rather than anywhere a
 *      second mapping could disagree with this one.
 *   3. Nothing — a billing block with just the contact details, as before.
 *
 * FAIL-SOFT throughout: CustomerContactReader answers `unavailable` rather than
 * throwing, and an order that ships to a stale address is a phone call, while an
 * order that was never created is money with no paperwork behind it.
 */
final class WooOrderAddress
{
    // === CONSTANTS ===
    // (The plan-address mapping consts moved WITH the mapping to
    // GiftShippingAddress::fromPlanContact — one mapping, shared with gifts.)

    /**
     * The `billing` and `shipping` blocks for one order.
     *
     * @return array{billing: array<string, string>, shipping: array<string, string>}
     */
    public static function forOrder(
        InstallmentPlan $plan,
        Shop $shop,
        int $customerId,
        ?CustomerContactReader $reader = null,
    ): array {
        $address = self::fromStore($plan, $shop, $customerId, $reader)
            ?? self::fromPlan($plan);

        $block = $address?->toWooBlock() ?? [];

        // The contact details are the plan's own: they are what the CHARGE was
        // made against, and a store profile edited afterwards must not silently
        // re-address the receipt for money that already moved.
        $contact = array_filter([
            'email' => (string) ($plan->customer_email ?? ''),
            'first_name' => (string) ($plan->customer_name ?? ''),
            'phone' => (string) ($plan->customer_phone ?? ''),
        ], static fn (string $v): bool => $v !== '');

        return [
            // The address wins on the name fields when it has them — "first_name
            // / last_name" from a real address block beats a single stored
            // full-name string.
            'billing' => $contact + $block,
            // No email on shipping: WooCommerce has no such field there, and a
            // package does not need one.
            'shipping' => $block,
        ];
    }

    /** The shopper's CURRENT address, as their store account holds it. */
    private static function fromStore(
        InstallmentPlan $plan,
        Shop $shop,
        int $customerId,
        ?CustomerContactReader $reader,
    ): ?GiftShippingAddress {
        if ($customerId <= 0) {
            return null; // a guest has no store profile to read
        }

        $contact = ($reader ?? new CustomerContactReader)->read($shop, (string) $customerId);

        return $contact->address?->isShippable() === true ? $contact->address : null;
    }

    /**
     * The address the plan itself carries — an imported member's, in the import's
     * own vocabulary. The mapping lives on GiftShippingAddress so gift orders and
     * subscription orders can never disagree about where the same person lives.
     */
    private static function fromPlan(InstallmentPlan $plan): ?GiftShippingAddress
    {
        return GiftShippingAddress::fromPlanContact($plan);
    }
}
