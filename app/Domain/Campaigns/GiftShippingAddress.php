<?php

namespace App\Domain\Campaigns;

use App\Models\InstallmentPlan;

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

    /** Joins a street with its building number: "הרצל 12". */
    private const STREET_SEPARATOR = ' ';

    /** Prefix for the apartment line — address_2 on a Woo block. */
    private const APARTMENT_PREFIX = 'דירה ';

    /**
     * A building number at the END of a street line — "הרצל 12", "הרצל 12א",
     * "הרצל 12/3". Only used when the store did not keep the number in a field
     * of its own; a line that does not end in one keeps the whole line as the
     * street rather than guessing.
     */
    private const TRAILING_BUILDING = '/^(.*\S)\s+(\d+[א-תa-zA-Z]?(?:\/\d+)?)$/u';

    /**
     * The labels the LETS address fields fold into address_2 on an order
     * ("דירה 3, קומה 2, כניסה א"), per part, in both languages the plugin writes.
     */
    private const ADDRESS2_LABELS = [
        'apartment' => 'דירה|apt\.?|apartment',
        'floor' => 'קומה|floor',
        'entrance' => 'כניסה|entrance',
    ];

    /**
     * Separators left at either end of address_2 once its parts are lifted out.
     * A regex, not trim(): trim() works on BYTES, and "·" is two of them.
     */
    private const EDGE_SEPARATORS = '/^[\s,·\-]+|[\s,·\-]+$/u';

    private const NOTE_SEPARATOR = ' · ';

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
        // The Israeli parts, when the store kept them in fields of their own
        // (the LETS address fields do). Null = fold them out of the lines.
        public ?string $building = null,
        public ?string $apartment = null,
        public ?string $floor = null,
        public ?string $entrance = null,
        // What the customer asked to have written on the delivery.
        public ?string $note = null,
    ) {}

    /**
     * Build from a WooCommerce `billing` or `shipping` block.
     *
     * @param  array<string, mixed>  $block
     * @param  array{building?: ?string, apartment?: ?string, floor?: ?string, entrance?: ?string, note?: ?string, phone?: ?string}  $extras
     *                                                                                                                                        the granular parts read beside the block, and a phone to fall back on
     */
    public static function fromWooBlock(array $block, array $extras = []): self
    {
        return new self(
            firstName: self::clean($block['first_name'] ?? null),
            lastName: self::clean($block['last_name'] ?? null),
            address1: self::clean($block['address_1'] ?? null),
            address2: self::clean($block['address_2'] ?? null),
            city: self::clean($block['city'] ?? null),
            zip: self::clean($block['postcode'] ?? null),
            countryCode: self::clean($block['country'] ?? null),
            phone: self::clean($block['phone'] ?? null) ?? self::clean($extras['phone'] ?? null),
            company: self::clean($block['company'] ?? null),
            building: self::clean($extras['building'] ?? null),
            apartment: self::clean($extras['apartment'] ?? null),
            floor: self::clean($extras['floor'] ?? null),
            entrance: self::clean($extras['entrance'] ?? null),
            note: self::clean($extras['note'] ?? null),
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

    /**
     * The address the plan ITSELF carries — an imported member's, in the
     * import's own vocabulary (street, building number, apartment), or an
     * admin's correction. This is THE one mapping of that shape; recurring
     * orders (WooOrderAddress) and gift orders both read through here, so the
     * two can never disagree about where the same person lives.
     *
     * Null when the plan holds no shippable address — a half-filled import
     * (a city and nothing else) is not a delivery instruction.
     */
    public static function fromPlanContact(InstallmentPlan $plan): ?self
    {
        $stored = $plan->contactAddress();
        if ($stored === []) {
            return null;
        }

        $street = trim(implode(self::STREET_SEPARATOR, array_filter([
            $stored['street'] ?? null,
            $stored['building_number'] ?? null,
        ])));

        $apartment = trim((string) ($stored['apartment_number'] ?? ''));

        $address = new self(
            firstName: self::clean($plan->customer_name),
            address1: self::clean($street),
            address2: $apartment !== '' ? self::APARTMENT_PREFIX.$apartment : null,
            city: self::clean($stored['city'] ?? null),
            zip: self::clean($stored['zip_code'] ?? null),
            countryCode: self::clean($stored['country'] ?? null),
            phone: self::clean($plan->customer_phone),
            building: self::clean($stored['building_number'] ?? null),
            apartment: self::clean($apartment),
            // The plan can now hold what the store's checkout asks for. A courier
            // sheet that names the flat but not the floor or the entrance is the
            // one that ends in a phone call from the doorway.
            floor: self::clean($stored['floor'] ?? null),
            entrance: self::clean($stored['entrance'] ?? null),
        );

        return $address->isShippable() ? $address : null;
    }

    /**
     * The address in the shape an Israeli courier's sheet asks for: street, house,
     * entrance, apartment, floor, and the note to write on the delivery.
     *
     * The granular fields win when the store kept them. Otherwise the parts are
     * folded out of the two lines — the building number off the end of the street
     * line, the labelled parts out of address_2 — and whatever address_2 says
     * that is NOT one of those parts goes into the note, where a courier will
     * still read it, rather than being dropped.
     *
     * @return array{street: string, building: string, entrance: string, apartment: string, floor: string, note: string}
     */
    public function deliveryParts(): array
    {
        $street = (string) $this->address1;
        $building = $this->building;

        if ($building !== null) {
            // An order's address_1 is "street building" — keep the street alone.
            $suffix = ' '.$building;
            if ($street !== $building && str_ends_with($street, $suffix)) {
                $street = substr($street, 0, -strlen($suffix));
            }
        } elseif (preg_match(self::TRAILING_BUILDING, $street, $match) === 1) {
            [$street, $building] = [$match[1], $match[2]];
        }

        $parts = ['apartment' => $this->apartment, 'floor' => $this->floor, 'entrance' => $this->entrance];
        $rest = (string) $this->address2;

        foreach (self::ADDRESS2_LABELS as $part => $labels) {
            $pattern = '/(?:^|[,·])\s*(?:'.$labels.')\s*:?\s*([^,·]+)/iu';
            if (preg_match($pattern, $rest, $match) === 1) {
                $parts[$part] ??= trim($match[1]);
                $rest = (string) preg_replace($pattern, '', $rest, 1);
            }
        }

        $rest = (string) preg_replace(self::EDGE_SEPARATORS, '', $rest);

        return [
            'street' => trim($street),
            'building' => (string) $building,
            'entrance' => (string) $parts['entrance'],
            'apartment' => (string) $parts['apartment'],
            'floor' => (string) $parts['floor'],
            'note' => implode(self::NOTE_SEPARATOR, array_filter(
                [$rest, $this->note],
                static fn (?string $value): bool => $value !== null && $value !== '',
            )),
        ];
    }

    /** The name to put on the package. */
    public function fullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
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
