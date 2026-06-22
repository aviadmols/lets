<?php

namespace App\Domain\Installments;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Resolves the SERVER-TRUSTED unit price for a variant from the local products
 * cache, under the currently-bound tenant. This is the money-safety anchor for the
 * deposit calculator: the storefront sends only a variant GID — NEVER a price — and
 * we read the authoritative price from our own synced cache (which mirrors Shopify),
 * scoped by BelongsToShop so one shop can never quote against another's catalog.
 *
 * A variant GID (gid://shopify/ProductVariant/123) maps to the cache's
 * external_variant_id ("123"); we strip the GID prefix and look it up. If the
 * variant isn't cached (never synced / bad id) we return null and the caller fails
 * closed — no price, no quote, no plan.
 */
final class ProductPriceResolver
{
    // === CONSTANTS ===
    /** Pull the trailing numeric id out of a Shopify GID. */
    private const GID_TAIL = '#/([0-9]+)$#';

    /**
     * The trusted unit price for the variant identified by $variantGid, scoped to
     * the bound tenant, or null when the variant is not in our cache.
     */
    public function priceFor(string $variantGid): ?float
    {
        $variant = $this->resolveVariant($variantGid);

        return $variant !== null ? round((float) $variant->price, 2) : null;
    }

    /**
     * The cached variant (+ product title) for building the deposit line item.
     *
     * @return array{variant: ProductVariant, title: string}|null
     */
    public function resolve(string $productGid, string $variantGid): ?array
    {
        $variant = $this->resolveVariant($variantGid);
        if ($variant === null) {
            return null;
        }

        $product = $variant->product;
        $title = trim(((string) ($product?->title ?? '')).' '.((string) ($variant->title ?? '')));

        return [
            'variant' => $variant,
            'title' => $title !== '' ? $title : (string) ($product?->title ?? __('storefront.installments.default_item')),
        ];
    }

    /** Look the variant up by its GID-derived external id, tenant-scoped. */
    private function resolveVariant(string $variantGid): ?ProductVariant
    {
        $externalId = self::numericId($variantGid);
        if ($externalId === '') {
            return null;
        }

        // BelongsToShop pins shop_id; eager-load the product for title/price display.
        return ProductVariant::query()
            ->with('product')
            ->where('external_variant_id', $externalId)
            ->first();
    }

    /** Extract the numeric tail of a GID, or return the input if it's already numeric. */
    public static function numericId(string $gid): string
    {
        $gid = trim($gid);
        if ($gid === '') {
            return '';
        }

        if (preg_match(self::GID_TAIL, $gid, $m) === 1) {
            return $m[1];
        }

        return ctype_digit($gid) ? $gid : '';
    }
}
