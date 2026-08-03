<?php

namespace App\Domain\Loyalty\Http;

use App\Models\LoyaltyAccount;
use App\Models\Shop;

/**
 * WHO is looking at the members page, and how we know.
 *
 * Two rails reach the same page with different proofs of identity: Shopify's App
 * Proxy puts `logged_in_customer_id` INSIDE the HMAC-signed query (so the id is
 * as trustworthy as the signature), while WooCommerce arrives on one of our own
 * temporary signed URLs minted for a logged-in WC user. Either way the customer
 * reference is server-established — the browser never asserts who it is.
 *
 * A visitor with no reference is not an error: it is a logged-out shopper, and
 * the page owes them a sign-in prompt, not somebody else's balance.
 */
final class LoyaltyVisitor
{
    // === CONSTANTS ===
    public const SOURCE_PROXY = 'proxy';       // Shopify App Proxy (signed query)
    public const SOURCE_SIGNED = 'signed';     // our own temporary signed URL (Woo)
    public const SOURCE_PREVIEW = 'preview';   // the admin's appearance preview

    private function __construct(
        public readonly Shop $shop,
        public readonly ?string $customerRef,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly string $source,
    ) {}

    public static function make(Shop $shop, ?string $customerRef, string $source, ?string $email = null, ?string $name = null): self
    {
        $ref = is_string($customerRef) ? trim($customerRef) : '';

        return new self(
            shop: $shop,
            customerRef: $ref !== '' ? mb_substr($ref, 0, 255) : null,
            email: self::clean($email),
            name: self::clean($name),
            source: $source,
        );
    }

    /** Did the platform tell us who this is? */
    public function isIdentified(): bool
    {
        return $this->customerRef !== null;
    }

    /** This visitor's membership, or null (not identified, or not a member yet). */
    public function account(): ?LoyaltyAccount
    {
        if (! $this->isIdentified()) {
            return null;
        }

        $account = LoyaltyAccount::query()->where('customer_ref', $this->customerRef)->first();
        if ($account !== null) {
            return $account;
        }

        // The same human can arrive under a different reference than the one they
        // joined with (a WooCommerce user id one day, a billing email the next).
        return $this->email !== null
            ? LoyaltyAccount::query()->where('customer_email', $this->email)->first()
            : null;
    }

    private static function clean(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
