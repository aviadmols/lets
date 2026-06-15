<?php

namespace App\Services\Products\Data;

/**
 * Source-agnostic variant DTO — the contract boundary for one product variant.
 *
 * NO Shopify/Woo types leak through this: a ProductSource maps its native shape
 * (Shopify variant node / Woo variation) INTO this DTO, and the ProductUpserter
 * reads ONLY this. That keeps the upsert + UI ignorant of which upstream the data
 * came from (the whole point of the source abstraction).
 */
final readonly class VariantData
{
    public function __construct(
        /** Upstream variant id, normalised to the numeric/opaque string we persist. */
        public string $externalId,
        public ?string $title = null,
        public ?string $sku = null,
        /** Decimal price as a string ("49.90") — never a float (money safety). */
        public string $price = '0',
        public int $position = 0,
    ) {}
}
