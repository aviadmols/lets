<?php

namespace App\Domain\Customers;

use App\Domain\Campaigns\GiftShippingAddress;

/**
 * A customer's contact details as the STORE holds them right now.
 *
 * Read through on every open and never persisted — the same law the gift feature
 * runs on. The SaaS keeps no addresses and no phone numbers, so there is no staler
 * second copy to drift, and no extra pile of personal data to redact when a
 * customer asks to be forgotten.
 *
 * `reason` is what makes this honest when there is nothing to show: a guest has no
 * store account to read or write, and Shopify refuses address fields until the
 * merchant's protected-data request is approved. Both are situations the merchant
 * can act on, and neither is an error.
 *
 * The address shape is GiftShippingAddress, reused rather than copied: "where this
 * person lives" is one idea, and two normalisers for it would drift.
 */
final readonly class CustomerContact
{
    // === CONSTANTS ===
    /** Nothing is wrong; the store simply has no details for this person yet. */
    public const REASON_EMPTY = 'empty';

    /** A guest checkout: there is no customer account in the store to edit. */
    public const REASON_GUEST = 'guest';

    /** Shopify has not approved this app for customer address fields yet. */
    public const REASON_ACCESS_PENDING = 'access_pending';

    /** The store could not be reached, or refused. Editing is not offered. */
    public const REASON_UNAVAILABLE = 'unavailable';

    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?GiftShippingAddress $address = null,
        public bool $editable = false,
        public ?string $reason = null,
    ) {}

    /** Nothing readable, and the merchant is told why. */
    public static function unavailable(string $reason): self
    {
        return new self(editable: false, reason: $reason);
    }

    public function name(): string
    {
        return trim(implode(' ', array_filter([$this->firstName, $this->lastName])));
    }

    /** Has the store anything at all on this person? */
    public function isEmpty(): bool
    {
        return $this->name() === ''
            && ($this->phone ?? '') === ''
            && ($this->address?->address1 ?? null) === null;
    }
}
