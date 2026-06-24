<?php

namespace App\Services\Orders;

use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyOrderStrategy;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;

/**
 * Resolves the PlatformOrderStrategy for a shop, keyed by the shop's `platform`.
 * Mirrors ProductSourceFactory (a per-shop, explicit resolution — never global
 * state — with a test fake() hook).
 *
 * NOTE on Shopify: the ChargeOrchestrator uses its DI-injected ShopifyOrderStrategy
 * directly for Shopify shops (so Shopify behaviour is byte-identical and the existing
 * Shopify tests are untouched). This factory is the authority for NON-Shopify shops.
 * Its `default` arm still returns the bound Shopify strategy for any direct caller, so
 * the factory is a complete platform authority. Returns null when a platform has no
 * strategy bound yet (the engine then runs decoupled for that shop — no order is
 * materialized, the money truth still lives in the ledger).
 */
final class PlatformOrderStrategyFactory
{
    /** Test override — route every for() to this strategy. Null in production. */
    private static ?PlatformOrderStrategy $fake = null;

    public static function for(Shop $shop): ?PlatformOrderStrategy
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return match ($shop->platform) {
            // WooCommerce: materialize WC orders per charge_context via the WC REST API.
            // The strategy resolves its per-shop WC client inside materialize() (no global).
            Shop::PLATFORM_WOOCOMMERCE => new WooCommerceOrderStrategy(),
            // Default + explicit Shopify resolve to the bound Shopify strategy.
            default => app(ShopifyOrderStrategy::class),
        };
    }

    /** Test hook: force every for() to return $strategy. */
    public static function fake(PlatformOrderStrategy $strategy): void
    {
        self::$fake = $strategy;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
