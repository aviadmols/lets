<?php

namespace App\Domain\Account\Offers;

use App\Models\Shop;

/**
 * Resolves the account-offer order writer for a shop, keyed by `platform` —
 * the same explicit per-shop resolution (never global state, with a test
 * fake() hook) as PlatformOrderStrategyFactory and its four siblings.
 *
 * Returns null for a platform with no writer: the purchase service treats that
 * as "unavailable" BEFORE any money moves, so an unknown platform can never
 * charge a card and lose the order.
 */
final class OfferOrderWriterFactory
{
    /** Test override — route every for() to this writer. Null in production. */
    private static ?OfferOrderWriter $fake = null;

    public static function for(Shop $shop): ?OfferOrderWriter
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return match ($shop->platform) {
            Shop::PLATFORM_WOOCOMMERCE => new WooAccountOfferOrderWriter,
            Shop::PLATFORM_SHOPIFY => new ShopifyAccountOfferOrderWriter,
            default => null,
        };
    }

    /** Test hook: force every for() to return $writer. */
    public static function fake(OfferOrderWriter $writer): void
    {
        self::$fake = $writer;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
