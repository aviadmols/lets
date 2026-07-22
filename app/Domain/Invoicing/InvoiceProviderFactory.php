<?php

namespace App\Domain\Invoicing;

use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceClient;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceProvider;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;

/**
 * Builds an invoice provider bound to ONE shop's decrypted credentials — the
 * invoicing sibling of PayPlusGatewayFactory::for() and
 * PlatformInvoiceServiceFactory::for(), and it follows their tenancy law exactly:
 * the shop's encrypted bag is decrypted ONCE and injected as constructor state,
 * never read from config() at call time, and an instance is never reused across
 * shops.
 *
 * Returns NULL — rather than throwing — when the module is off, unconfigured, or
 * points at an unknown provider. Every call site is therefore a clean no-op for a
 * merchant who has not opted in: with invoicing disabled, the charge pipeline
 * behaves exactly as it did before this module existed.
 *
 * `for()` respects the merchant's `enabled` switch; `connectionFor()` deliberately
 * does NOT, so the settings screen can test credentials before switching the
 * module on.
 */
final class InvoiceProviderFactory
{
    /**
     * Optional per-shop provider override resolver. ONLY for tests — lets a test
     * swap in a fake provider without real HTTP. Null in production.
     *
     * @var (callable(Shop): ?InvoiceProvider)|null
     */
    private static $fakeResolver = null;

    /**
     * The provider for a shop that has invoicing ENABLED and connected, else null.
     * This is what the money-path hooks call.
     */
    public static function for(Shop $shop): ?InvoiceProvider
    {
        $settings = MerchantInvoicingSettings::forShop((int) $shop->getKey());

        // The merchant's master switch is checked BEFORE the test hook, deliberately:
        // a fake that short-circuits first would bypass the very gate that keeps a
        // shop which never opted in from having documents issued against its books —
        // and no test could then prove the gate works.
        if (! $settings->isEnabled()) {
            return null;
        }

        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($shop);
        }

        return self::build($shop, $settings);
    }

    /**
     * The provider for a shop's CREDENTIALS, ignoring the enabled switch. Used by
     * the settings screen's "Test connection" so a merchant can verify their keys
     * before turning the module on.
     */
    public static function connectionFor(Shop $shop): ?InvoiceProvider
    {
        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($shop);
        }

        return self::build($shop, MerchantInvoicingSettings::forShop((int) $shop->getKey()));
    }

    private static function build(Shop $shop, MerchantInvoicingSettings $settings): ?InvoiceProvider
    {
        if (! $shop->hasInvoicingConnection()) {
            return null;
        }

        $credentials = $shop->invoicingConfig();

        return match ($credentials['provider']) {
            Shop::INVOICING_PROVIDER_GREEN_INVOICE => new GreenInvoiceProvider(
                client: new GreenInvoiceClient(
                    credentials: $credentials,
                    shopId: (int) $shop->getKey(),
                    timeout: (int) config('invoicing.timeout', 20),
                ),
                settings: $settings,
            ),
            // An unknown stored provider is a no-op, never a guess at which books
            // to write to.
            default => null,
        };
    }

    /**
     * Test hook: route every for()/connectionFor() through $resolver.
     *
     * @param  callable(Shop): ?InvoiceProvider  $resolver
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
