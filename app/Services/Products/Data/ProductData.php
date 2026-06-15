<?php

namespace App\Services\Products\Data;

use Carbon\CarbonInterface;

/**
 * Source-agnostic product DTO — the contract boundary between a ProductSource and
 * the ProductUpserter / UI. A source maps its native catalog shape INTO this; the
 * upserter persists ONLY from this. NO Shopify/Woo types leak through.
 *
 * `status` / `onlineStoreStatus` are already mapped to the LOCAL taxonomy
 * (App\Models\Product::STATUS_* / ONLINE_*) by the source, so the upserter writes
 * them verbatim and never has to know an upstream enum (ACTIVE/ARCHIVED, etc.).
 */
final readonly class ProductData
{
    public function __construct(
        /** Upstream product id, normalised to the string we persist (e.g. "5001"). */
        public string $externalId,
        public string $title,
        public ?string $handle = null,
        /** LOCAL status: Product::STATUS_ACTIVE|STATUS_DRAFT|STATUS_UNLISTED. */
        public string $status = 'active',
        /** LOCAL online-store status: Product::ONLINE_PUBLISHED|ONLINE_UNPUBLISHED. */
        public string $onlineStoreStatus = 'unpublished',
        public ?string $imageUrl = null,
        /** @var array<int, string> */
        public array $tags = [],
        /** Upstream's own last-modified time (for staleness checks); may be null. */
        public ?CarbonInterface $updatedAtExternal = null,
        /** @var array<int, VariantData> */
        public array $variants = [],
    ) {}
}
