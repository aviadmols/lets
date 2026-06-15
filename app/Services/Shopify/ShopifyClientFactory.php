<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use RuntimeException;

/**
 * Builds a Shopify Admin client bound to ONE shop's decrypted offline token.
 *
 * The Shopify mirror of PayPlusGatewayFactory::for($shop): it REPLACES the
 * reference engine's global config('shopify.admin_api_base') +
 * config('shopify.admin_access_token') reads — the seam that let Shop B's job
 * accidentally hit Shop A's store. Credentials are decrypted ONCE here and held
 * as constructor state on the returned client; the instance is never reused
 * across shops.
 *
 * Operational config (api_version, timeouts, rate-limit knobs) is platform-wide
 * and comes from config/shopify.php — not secret, safe to share.
 */
final class ShopifyClientFactory
{
    /**
     * Optional test override — route every for() through a fake resolver so tests
     * never make real HTTP. Null in production. Set via fake(); cleared via clearFake().
     *
     * @var (callable(Shop): ShopifyAdminApi)|null
     */
    private static $fakeResolver = null;

    public static function for(Shop $shop): ShopifyAdminApi
    {
        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($shop);
        }

        $token = $shop->shopifyAccessToken();
        if ($token === null) {
            throw new RuntimeException(
                "Shop {$shop->getKey()} has no Shopify access token (not installed or uninstalled)."
            );
        }

        return new ShopifyAdminClient(
            shopId: (int) $shop->getKey(),
            shopDomain: (string) $shop->shopify_domain,
            accessToken: $token,
            apiVersion: (string) config('shopify.api_version'),
            rateLimiter: new ShopifyRateLimiter(
                // Disable real sleeps during tests so the suite never blocks.
                sleepEnabled: ! app()->runningUnitTests(),
            ),
        );
    }

    /**
     * Test hook: route every ShopifyClientFactory::for() through $resolver.
     *
     * @param  callable(Shop): ShopifyAdminApi  $resolver
     */
    public static function fake(callable $resolver): void
    {
        self::$fakeResolver = $resolver;
    }

    public static function clearFake(): void
    {
        self::$fakeResolver = null;
    }
}
