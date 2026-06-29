<?php

namespace App\Filament\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductRefreshService;
use App\Support\Tenant;
use Filament\Notifications\Notification;

/**
 * Reusable product-picker seam for the Flow Builder drawers. The OFFER drawer
 * (which product to charge) and the TRIGGER "specific product" rule (which
 * purchase qualifies) both let the merchant SEARCH the shop's synced catalog and
 * SELECT a product instead of typing an opaque id/gid.
 *
 * Source-agnostic: the same Product/ProductVariant cache backs both Shopify and
 * WooCommerce shops (both sync into the same tables; external_id is numeric for
 * both). The ONE thing that differs is the stored identifier FORMAT, which the
 * charge engine + the upsell resolver expect — encapsulated here in
 * productIdentifier()/variantIdentifier() so both call sites agree.
 *
 * Tenant-safety by construction: every lookup runs the Product::query() under the
 * BelongsToShop global scope, so a foreign/nonexistent id resolves to null and is
 * a silent no-op (never another shop's row). shop_id + status are NEVER taken
 * from input — the scoped query owns isolation; the caller only writes the
 * platform identifiers + the auto-filled title/price.
 */
trait PicksProducts
{
    // === CONSTANTS ===
    /** Mirror ProductResource: search is a no-op below this length (perf + intent). */
    public const PICKER_MIN_SEARCH_CHARS = 3;

    /** Cap the candidate list so a broad term can't scan/paint the whole catalog. */
    public const PICKER_RESULT_LIMIT = 25;

    /**
     * Whether a search term is long enough to query (>= the min). Public so the
     * picker Blade can branch the "type at least N characters" hint WITHOUT
     * touching the trait constant directly (PHP forbids Trait::CONST access).
     */
    public function pickerTermSearchable(string $term): bool
    {
        return mb_strlen(trim($term)) >= self::PICKER_MIN_SEARCH_CHARS;
    }

    /**
     * Search the tenant's ACTIVE catalog for the picker. Mirrors
     * ProductResource::applySearch (title + variant title/sku + external ids) but
     * only over active products (a picker never offers a draft/unlisted item), and
     * eager-loads variants so the result rows can show price/sku without N+1.
     *
     * Returns an empty collection under the min length — typing one letter never
     * touches the catalog.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    protected function pickerResults(string $term): \Illuminate\Support\Collection
    {
        if (! $this->pickerTermSearchable($term)) {
            return collect();
        }

        $term = trim($term);

        $like = '%'.$term.'%';

        // Tenant scope is automatic via BelongsToShop — never withoutGlobalScope.
        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->with(['variants' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->where(function ($q) use ($like): void {
                $q->where('title', 'like', $like)
                    ->orWhere('external_id', 'like', $like)
                    ->orWhereHas('variants', function ($vq) use ($like): void {
                        $vq->where('title', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('external_variant_id', 'like', $like);
                    });
            })
            ->orderBy('title')
            ->orderBy('id')
            ->limit(self::PICKER_RESULT_LIMIT)
            ->get();
    }

    /**
     * Tenant-scoped lookup of one picked product (active), or null. A foreign /
     * nonexistent id resolves to null under the global scope — the caller MUST
     * treat null as "reject, write nothing" so a spoofed id can never persist.
     */
    protected function pickedProduct(int $productId): ?Product
    {
        if ($productId <= 0) {
            return null;
        }

        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->with(['variants' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->find($productId);
    }

    /**
     * Tenant-scoped lookup of one variant that belongs to the given product, or
     * null. Scoped through the product's variants() relation so a variant from
     * another product (or shop) can't be paired onto this offer.
     */
    protected function pickedVariant(Product $product, int $variantId): ?ProductVariant
    {
        if ($variantId <= 0) {
            return null;
        }

        return $product->variants()->find($variantId);
    }

    /**
     * The product identifier in the PLATFORM FORMAT the charge engine + the upsell
     * resolver expect:
     *   - Shopify   → 'gid://shopify/Product/{external_id}'  (the resolver compares
     *                 trigger->shopify_product_gid against PurchaseContext gids, and
     *                 the charge sends the offer's variant gid to Shopify draft-order)
     *   - WooCommerce → the RAW numeric external_id (Woo has no gids)
     */
    protected function productIdentifier(Product $product): string
    {
        return $product->source === Product::SOURCE_SHOPIFY
            ? 'gid://shopify/Product/'.$product->external_id
            : (string) $product->external_id;
    }

    /**
     * The variant identifier in the platform format. Shopify → a ProductVariant
     * gid (the offer_variant_gid the child-order line item targets); Woo → the raw
     * numeric variation id. Null when the product has no variant (rare; the offer
     * then has no variant target — discountedPrice() still rules the money).
     */
    protected function variantIdentifier(Product $product, ?ProductVariant $variant): ?string
    {
        if ($variant === null) {
            return null;
        }

        return $product->source === Product::SOURCE_SHOPIFY
            ? 'gid://shopify/ProductVariant/'.$variant->external_variant_id
            : (string) $variant->external_variant_id;
    }

    /**
     * Trigger a full tenant-scoped catalog re-sync (the SAME backend seam the
     * Products list uses). Async upsert; the picker re-runs its search on the next
     * keystroke against the refreshed cache. No-op when no tenant is bound.
     */
    protected function refreshPickerCatalog(): void
    {
        $shop = Tenant::current();

        if ($shop === null) {
            return;
        }

        app(ProductRefreshService::class)->refreshAll($shop);

        Notification::make()->title(__('upsell.admin.picker.refresh_queued'))->success()->send();
    }
}
