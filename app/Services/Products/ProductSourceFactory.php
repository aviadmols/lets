<?php

namespace App\Services\Products;

use App\Models\Shop;
use App\Services\Products\Sources\ProductSource;
use App\Services\Products\Sources\ShopifyProductSource;
use App\Services\Products\Sources\WooCommerceProductSource;

/**
 * Resolves the ProductSource for a shop, keyed by the shop's `platform`. The single
 * place product sync decides "where do this shop's products come from" — the
 * import job + webhook handler call this and stay source-agnostic.
 *
 * Mirrors PayPlusGatewayFactory/ShopifyClientFactory: a per-shop, explicit
 * resolution (never global state), with a test fake() hook to inject a canned
 * source.
 */
final class ProductSourceFactory
{
    /**
     * Test override — route every for() to this source. Null in production.
     *
     * @var ProductSource|null
     */
    private static ?ProductSource $fake = null;

    public static function for(Shop $shop): ProductSource
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return match ($shop->platform) {
            Shop::PLATFORM_WOOCOMMERCE => new WooCommerceProductSource(),
            // Default + explicit Shopify both resolve to the Shopify source.
            default => new ShopifyProductSource(),
        };
    }

    /** Test hook: force every for() to return $source. */
    public static function fake(ProductSource $source): void
    {
        self::$fake = $source;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
