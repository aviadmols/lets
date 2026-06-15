<?php

namespace App\Services\Products\Sources;

use App\Models\Shop;
use App\Services\Products\Data\ProductData;
use App\Services\Products\Data\ProductPage;

/**
 * A catalog source — the seam that makes product sync source-agnostic. Shopify is
 * the live Stage-1 implementation; WooCommerce is the Stage-2 placeholder. The
 * ProductSourceFactory picks one per shop (keyed by the shop's `platform`).
 *
 * Contract: every method takes the Shop EXPLICITLY (the source is stateless and
 * builds a per-shop client internally — never a global token), and returns ONLY
 * the readonly DTOs (ProductData/VariantData/ProductPage). No upstream type leaks
 * out, so the import job + webhook handler + UI never depend on Shopify/Woo.
 */
interface ProductSource
{
    /**
     * Fetch one page of products. `$cursor` is the opaque continuation token from
     * the previous page's ProductPage::$nextCursor (null = first page). The import
     * loop calls this until the returned page's nextCursor is null.
     *
     * @param  array<string, mixed>  $filters  optional source-specific filters (reserved)
     */
    public function fetchPage(Shop $shop, ?string $cursor, array $filters = []): ProductPage;

    /**
     * Fetch a single product by its upstream id (a Shopify GID/numeric id or a Woo
     * id). Returns null when the product no longer exists upstream (deleted). Used
     * by the product webhook handler to refresh exactly one product.
     */
    public function fetchOne(Shop $shop, string $externalId): ?ProductData;

    /** The Shop::PLATFORM_* this source serves (shopify|woocommerce). */
    public function platform(): string;
}
