<?php

namespace App\Domain\Campaigns;

/**
 * A shipping address, normalised from whichever platform shape it came out of.
 *
 * Deliberately a value object and NOT a database row: the SaaS stores no
 * addresses. One is read from the store at the moment a gift order is created and
 * discarded straight after, so the package goes where the customer lives today and
 * the app never becomes a second, staler copy of the merchant's customer records
 * (nor a place their personal data has to be redacted from later).
 */
final readonly class GiftShippingAddress
{
    // === CONSTANTS ===
    /**
     * The minimum that makes an address SHIPPABLE. A row with a name and a country
     * but no street is not an address — creating an order against it produces a
     * package nobody can deliver, which is worse than skipping the recipient and
     * telling the merchant why.
     */
    private const REQUIRED = ['address1', 'city'];

    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $address1 = null,
        public ?string $address2 = null,
        public ?string $city = null,
        public ?string $zip = null,
        public ?string $countryCode = null,
        public ?string $phone = null,
        public ?string $company = null,
    ) {}

    /**
     * Build from a WooCommerce `billing` or `shipping` block.
     *
     * @param  array<string, mixed>  $block
     */
    public static function fromWooBlock(array $block): self
    {
        return new self(
            firstName: self::clean($block['first_name'] ?? null),
            lastName: self::clean($block['last_name'] ?? null),
            address1: self::clean($block['address_1'] ?? null),
            address2: self::clean($block['address_2'] ?? null),
            city: self::clean($block['city'] ?? null),
            zip: self::clean($block['postcode'] ?? null),
            countryCode: self::clean($block['country'] ?? null),
            phone: self::clean($block['phone'] ?? null),
            company: self::clean($block['company'] ?? null),
        );
    }

    /**
     * Build from a Shopify MailingAddress node (customer defaultAddress or an
     * order's shippingAddress — the field names are the same on both).
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromShopifyNode(array $node): self
    {
        return new self(
            firstName: self::clean($node['firstName'] ?? null),
            lastName: self::clean($node['lastName'] ?? null),
            address1: self::clean($node['address1'] ?? null),
            address2: self::clean($node['address2'] ?? null),
            city: self::clean($node['city'] ?? null),
            zip: self::clean($node['zip'] ?? null),
            countryCode: self::clean($node['countryCode'] ?? null),
            phone: self::clean($node['phone'] ?? null),
            company: self::clean($node['company'] ?? null),
        );
    }

    /** Is there enough here to actually ship a package? */
    public function isShippable(): bool
    {
        foreach (self::REQUIRED as $field) {
            if (($this->{$field} ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The WooCommerce `shipping` block shape.
     *
     * @return array<string, string>
     */
    public function toWooBlock(): array
    {
        return array_filter([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'address_1' => $this->address1,
            'address_2' => $this->address2,
            'city' => $this->city,
            'postcode' => $this->zip,
            'country' => $this->countryCode,
            'phone' => $this->phone,
        ], static fn (?string $v): bool => $v !== null);
    }

    /**
     * The Shopify REST `shipping_address` shape.
     *
     * @return array<string, string>
     */
    public function toShopifyBlock(): array
    {
        return array_filter([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'zip' => $this->zip,
            'country_code' => $this->countryCode,
            'phone' => $this->phone,
        ], static fn (?string $v): bool => $v !== null);
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
