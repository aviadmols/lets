<?php

namespace App\Services\Orders;

use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyPaidOrderPlanResolver;
use App\Services\WooCommerce\Orders\WooCommercePaidOrderPlanResolver;

/**
 * Resolves the PaidOrderPlanResolver for a shop, keyed by the shop's `platform`.
 * Mirrors the other platform factories. Returns null when a platform has no resolver
 * yet (WooCommerce ships in W11 P2) — PlanActivationService then treats the paid
 * order as "not a LETS deposit" (no activation), which is the safe default.
 */
final class PaidOrderPlanResolverFactory
{
    /** Test override — route every for() to this resolver. Null in production. */
    private static ?PaidOrderPlanResolver $fake = null;

    public static function for(Shop $shop): ?PaidOrderPlanResolver
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return match ($shop->platform) {
            // WooCommerce: find the plan by the callback's echoed more_info / order meta.
            Shop::PLATFORM_WOOCOMMERCE => app(WooCommercePaidOrderPlanResolver::class),
            // Default + explicit Shopify resolve to the Shopify note-attr/draft resolver.
            default => app(ShopifyPaidOrderPlanResolver::class),
        };
    }

    /** Test hook: force every for() to return $resolver. */
    public static function fake(PaidOrderPlanResolver $resolver): void
    {
        self::$fake = $resolver;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
