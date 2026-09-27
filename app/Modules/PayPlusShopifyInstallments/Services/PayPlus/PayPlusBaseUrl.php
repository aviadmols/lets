<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

/**
 * The PayPlus API hosts a shop may talk to: PayPlus's own production and
 * sandbox endpoints (config/payplus.php), and nothing else.
 *
 * A per-shop `base_url` is a CHOICE between those two, never a free URL. Every
 * charge, refund, token lookup and page mint is sent to it with the merchant's
 * API key and secret, so a value that is not PayPlus's own host would hand
 * those credentials to whoever answers — and a fake "approved" would mint
 * ledger rows and real tax documents with no money moved.
 *
 * resolve() is applied where the value is READ (Shop::payplusConfig, discovery),
 * so a stored value from before this wall existed can never steer a request.
 */
final class PayPlusBaseUrl
{
    // === CONSTANTS ===
    private const CONFIG_PRODUCTION = 'payplus.base_url';

    private const CONFIG_SANDBOX = 'payplus.base_url_sandbox';

    /** @return list<string> the allowed base URLs, normalised (no trailing slash) */
    public static function allowed(): array
    {
        return array_values(array_unique(array_filter([
            self::normalise((string) config(self::CONFIG_PRODUCTION)),
            self::normalise((string) config(self::CONFIG_SANDBOX)),
        ])));
    }

    public static function isAllowed(?string $candidate): bool
    {
        $candidate = self::normalise((string) $candidate);

        return $candidate !== '' && in_array($candidate, self::allowed(), true);
    }

    /** The candidate when it is one of PayPlus's own hosts, else the production host. */
    public static function resolve(?string $candidate): string
    {
        return self::isAllowed($candidate)
            ? self::normalise((string) $candidate)
            : self::normalise((string) config(self::CONFIG_PRODUCTION));
    }

    private static function normalise(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }
}
