<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use RuntimeException;

/**
 * Builds a PayPlus gateway bound to ONE shop's decrypted credentials. This
 * REPLACES the reference engine's global container bind
 * (PayPlusShopifyInstallmentsServiceProvider.php:34) — the line that let Shop
 * B's job accidentally read Shop A's terminal because the gateway pulled creds
 * from config(). Here the credentials are injected as constructor state and the
 * instance is never reused across shops.
 *
 * Operational config (api_prefix, timeout, currency) is platform-wide and comes
 * from config/payplus.php — NOT secret, safe to share.
 */
final class PayPlusGatewayFactory
{
    /**
     * Optional per-shop gateway override resolver. ONLY for tests — lets a test
     * swap in a fake gateway without real HTTP. Null in production. Set via
     * fake(); cleared via clearFake().
     *
     * @var (callable(Shop): PayPlusGatewayInterface)|null
     */
    private static $fakeResolver = null;

    public static function for(Shop $shop): PayPlusGatewayInterface
    {
        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($shop);
        }

        $credentials = $shop->payplusConfig();

        if (empty($credentials['api_key']) || empty($credentials['secret_key'])) {
            throw new RuntimeException(
                "Shop {$shop->getKey()} has no PayPlus connection configured."
            );
        }

        return new PayPlusGateway(
            credentials: $credentials,
            apiPrefix: (string) config('payplus.api_prefix', '/api/v1.0'),
            timeout: (int) config('payplus.timeout', 30),
            currency: (string) config('payplus.currency', 'ILS'),
        );
    }

    /**
     * Test hook: route every PayPlusGatewayFactory::for() through $resolver.
     *
     * @param callable(Shop): PayPlusGatewayInterface $resolver
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
