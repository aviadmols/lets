<?php

namespace App\Services\WooCommerce;

use App\Models\Shop;
use RuntimeException;

/**
 * Builds a WooCommerceClient bound to ONE shop's decrypted WooCommerce REST creds
 * (base_url + consumer key/secret). The WooCommerce mirror of ShopifyClientFactory /
 * PayPlusGatewayFactory::for($shop): credentials are read from the shop's encrypted
 * woocommerce_credentials bag and held as constructor state on the returned client;
 * the instance is never reused across shops. A test fake() hook routes every for()
 * through a resolver so tests never make real HTTP.
 */
final class WooClientFactory
{
    /** @var (callable(Shop): WooCommerceClient)|null */
    private static $fakeResolver = null;

    public static function for(Shop $shop): WooCommerceClient
    {
        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($shop);
        }

        $cfg = $shop->wooConfig();
        if (empty($cfg['base_url']) || empty($cfg['consumer_key']) || empty($cfg['consumer_secret'])) {
            throw new RuntimeException("Shop {$shop->getKey()} has no WooCommerce REST credentials.");
        }

        return new WooCommerceClient(
            baseUrl: (string) $cfg['base_url'],
            consumerKey: (string) $cfg['consumer_key'],
            consumerSecret: (string) $cfg['consumer_secret'],
            timeout: (int) config('woocommerce.timeout', 30),
        );
    }

    /** @param callable(Shop): WooCommerceClient $resolver */
    public static function fake(callable $resolver): void
    {
        self::$fakeResolver = $resolver;
    }

    public static function clearFake(): void
    {
        self::$fakeResolver = null;
    }
}
