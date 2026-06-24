<?php

namespace App\Services\Orders;

use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyDepositInvoiceAdapter;
use App\Services\Shopify\Orders\ShopifyDraftOrderService;
use App\Services\Shopify\ShopifyClientFactory;

/**
 * Resolves the PlatformInvoiceService for a shop, keyed by the shop's `platform`.
 * Mirrors ProductSourceFactory / PlatformOrderStrategyFactory. Returns null when a
 * platform has no deposit-invoice service bound yet (WooCommerce ships in W11 P2);
 * DepositPlanService guards against null before creating a plan.
 *
 * The Shopify adapter is built with a PER-SHOP client (ShopifyClientFactory::for) —
 * the same pattern UpsellChargeService uses — because ShopifyAdminApi is never a
 * container singleton; it is resolved per shop from that shop's encrypted token.
 */
final class PlatformInvoiceServiceFactory
{
    /** Test override — route every for() to this service. Null in production. */
    private static ?PlatformInvoiceService $fake = null;

    public static function for(Shop $shop): ?PlatformInvoiceService
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return match ($shop->platform) {
            // WooCommerceDepositInvoiceService (the PayPlus hosted page) ships in W11 P2.
            Shop::PLATFORM_WOOCOMMERCE => null,
            // Default + explicit Shopify: the draft-order adapter over a per-shop client.
            default => new ShopifyDepositInvoiceAdapter(
                new ShopifyDraftOrderService(ShopifyClientFactory::for($shop)),
            ),
        };
    }

    /** Test hook: force every for() to return $service. */
    public static function fake(PlatformInvoiceService $service): void
    {
        self::$fake = $service;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
