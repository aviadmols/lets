<?php

namespace App\Support\Ui;

use App\Models\Product;

/**
 * ONE product picker, wherever an admin screen has to choose one.
 *
 * Two screens now offer the same choice — the next cycle's contents on a
 * subscription, and the product a hand-typed subscription is for — and a third
 * will. They must list the same catalog, in the same order, priced the same way,
 * or the merchant learns that "the product list" means something different
 * depending on which button they pressed.
 *
 * Tenant-scoped by Product's BelongsToShop global scope: this never names a
 * shop_id, and with no tenant bound it returns nothing rather than everything.
 */
final class ProductOptions
{
    // === CONSTANTS ===
    /** What separates a product's title from its price in the option label. */
    public const PRICE_SEPARATOR = ' · ';

    /**
     * The catalog as select options: external product id => "Title · ₪49.00".
     *
     * The price shown is the FIRST variant's — the one a subscription for this
     * product would bill — and a product with no variants simply shows its name.
     *
     * @return array<string, string>
     */
    public static function forSelect(?string $currency = null): array
    {
        $currency = trim((string) $currency) !== '' ? (string) $currency : Money::DEFAULT_CURRENCY;

        return Product::query()
            ->with('variants')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(function (Product $product) use ($currency): array {
                $variant = $product->variants->sortBy('position')->first();
                $price = $variant !== null
                    ? self::PRICE_SEPARATOR.Money::format((float) $variant->price, $currency)
                    : '';

                return [(string) $product->external_id => trim((string) $product->title).$price];
            })
            ->all();
    }

    /**
     * The same catalog as bare TITLES, keyed by external id — for a form that
     * has to name a product without pricing it (picking a product prefills the
     * subscription's name, and "Coffee club · ₪49.00" is not a name).
     *
     * Deliberately NOT memoized in a static. The tenant scope is evaluated when
     * the query runs, so a remembered catalog is one that outlives the shop it
     * was read for — and a platform admin moving between shops in one process
     * would be shown the previous shop's products.
     *
     * @return array<string, string>
     */
    public static function titles(): array
    {
        return Product::query()
            ->orderBy('title')
            ->pluck('title', 'external_id')
            ->mapWithKeys(static fn (?string $title, $id): array => [
                (string) $id => trim((string) $title),
            ])
            ->all();
    }
}
