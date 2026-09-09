<?php

namespace App\Domain\Refunds;

use App\Domain\Refunds\Contracts\StoreRefunder;
use App\Models\Shop;

/**
 * Which store gets told about this refund — the per-shop resolver, mirroring
 * `PlatformOrderStrategyFactory` and `InvoiceProviderFactory`.
 *
 * Returns NULL when there is nobody to tell: a shop with no live connection to
 * its platform, or a platform this app has no refunder for. Null is a first-class
 * answer, not a failure — the orchestrator records the store leg as `skipped` and
 * the refund still completes, because the money moving is what the customer
 * cares about and a merchant with a disconnected store has a different problem.
 */
final class StoreRefunderFactory
{
    // === CONSTANTS ===
    /** platform => the refunder that speaks to it. */
    private const REFUNDERS = [
        Shop::PLATFORM_WOOCOMMERCE => WooStoreRefunder::class,
        Shop::PLATFORM_SHOPIFY => ShopifyStoreRefunder::class,
    ];

    /** A fake, for tests and for the platform-admin harness. */
    private static ?\Closure $fake = null;

    public static function for(Shop $shop): ?StoreRefunder
    {
        if (self::$fake !== null) {
            return (self::$fake)($shop);
        }

        $class = self::REFUNDERS[(string) $shop->platform] ?? null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        /** @var StoreRefunder $refunder */
        $refunder = app($class);

        return $refunder->supports($shop) ? $refunder : null;
    }

    /** @param \Closure(Shop): ?StoreRefunder $factory */
    public static function fake(\Closure $factory): void
    {
        self::$fake = $factory;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
