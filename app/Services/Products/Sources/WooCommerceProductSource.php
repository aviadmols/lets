<?php

namespace App\Services\Products\Sources;

use App\Models\Shop;
use App\Services\Products\Data\ProductData;
use App\Services\Products\Data\ProductPage;
use RuntimeException;

/**
 * Stage-2 placeholder. Proves the source abstraction stands for a NON-Shopify
 * upstream without wiring it live. Implementing the interface means the import
 * job + product webhook handler + UI need ZERO changes when Woo goes live — only
 * the factory's switch arm flips.
 *
 * Stage-2 implementation sketch:
 *   - Per-shop creds: a WordPress base URL + an encrypted consumer key/secret on
 *     the Shop row (same encrypted-bag pattern as the PayPlus credentials), read
 *     here as constructor state via a WooClientFactory::for($shop) — never global.
 *   - Read: GET {base}/wp-json/wc/v3/products?per_page=100&page={n}  (Basic auth
 *     with ck/cs over HTTPS). Map each product → ProductData: id→externalId,
 *     name→title, slug→handle, status (publish→active / draft→draft / private→
 *     unlisted), catalog_visibility→onlineStoreStatus, images[0].src→imageUrl,
 *     tags[].name→tags, date_modified_gmt→updatedAtExternal, and variations (for
 *     variable products: GET …/products/{id}/variations) → VariantData
 *     (id→externalId, attribute summary→title, sku, price).
 *   - Paginate by page number (Woo returns X-WP-TotalPages); the opaque cursor is
 *     just the next page number as a string. fetchOne = GET …/products/{id}.
 *   - A WordPress/Woo webhook (product.created/updated/deleted) routes to the SAME
 *     ProductWebhookHandler (it is source-agnostic — it calls fetchOne+upsert).
 */
final class WooCommerceProductSource implements ProductSource
{
    private const NOT_IMPLEMENTED = 'WooCommerceProductSource is a Stage-2 placeholder and is not wired live yet.';

    public function platform(): string
    {
        return Shop::PLATFORM_WOOCOMMERCE;
    }

    public function fetchPage(Shop $shop, ?string $cursor, array $filters = []): ProductPage
    {
        // Stage-2: replace with the WC REST /products page read described above.
        // Returning an empty page keeps an accidental import a safe no-op rather
        // than a crash; the explicit throw lives on fetchOne so a wired flow fails
        // loudly if it ever reaches the unimplemented single-fetch path.
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    public function fetchOne(Shop $shop, string $externalId): ?ProductData
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }
}
