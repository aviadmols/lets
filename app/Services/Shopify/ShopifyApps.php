<?php

namespace App\Services\Shopify;

use App\Models\Shop;

/**
 * The registry of every Shopify Partner app this ONE deployment serves
 * (config('shopify.apps')): the PUBLIC App-Store app and the CUSTOM stage-1 app
 * that real test stores install through. One codebase, one Railway service, one
 * database — the shop row remembers which app installed it (shops.shopify_app_key)
 * and every identity-sensitive seam resolves through here:
 *
 *   - OAuth install/callback        → credentials($appKey) / matchQueryHmac()
 *   - Managed-install token exchange → credentials($appKey)
 *   - Session tokens (App Bridge)   → verifySessionToken() tries each app's
 *     (secret, api_key) pair — the JWT `aud` pins which app minted it
 *   - Webhook / App-Proxy HMAC      → secrets() (loop, timing-safe each)
 *   - App Bridge <meta> api key     → apiKeyForShopDomain()
 *
 * An app with an empty api_key or api_secret is NOT configured and is skipped
 * everywhere, so a public-only environment behaves exactly as before.
 */
final class ShopifyApps
{
    // === CONSTANTS ===
    /** The public App-Store app — the default identity, and the legacy config. */
    public const PUBLIC = 'public';
    /** The custom (single-store install links, no review) stage-1 app. */
    public const CUSTOM = 'custom';

    /** @return list<string> keys of the apps that actually have credentials */
    public static function keys(): array
    {
        $keys = array_map('strval', array_keys((array) config('shopify.apps', [])));
        if (! in_array(self::PUBLIC, $keys, true)) {
            $keys[] = self::PUBLIC; // legacy env: only the top-level api_key/api_secret
        }

        return array_values(array_filter($keys, static function (string $key): bool {
            $creds = self::credentials($key);

            return $creds['api_key'] !== '' && $creds['api_secret'] !== '';
        }));
    }

    public static function isConfigured(string $appKey): bool
    {
        return in_array($appKey, self::keys(), true);
    }

    /**
     * A known app key, or the public default. NEVER trusts arbitrary input into
     * config lookups — an unknown/unconfigured key degrades to 'public'.
     */
    public static function normalize(?string $appKey): string
    {
        return ($appKey !== null && self::isConfigured($appKey)) ? $appKey : self::PUBLIC;
    }

    /** @return array{api_key: string, api_secret: string, handle: string, oauth_scopes: string} */
    public static function credentials(string $appKey): array
    {
        $apps = (array) config('shopify.apps', []);
        $app = (array) ($apps[$appKey] ?? []);

        // The PUBLIC app IS the legacy top-level config: the apps-map entry mirrors
        // the same env vars, so the top-level keys stay authoritative — a runtime
        // override of config('shopify.api_key') (tests, tinker) must keep winning.
        if ($appKey === self::PUBLIC) {
            return [
                'api_key' => (string) (config('shopify.api_key', '') ?: ($app['api_key'] ?? '')),
                'api_secret' => (string) (config('shopify.api_secret', '') ?: ($app['api_secret'] ?? '')),
                'handle' => (string) (config('shopify.app_handle', '') ?: ($app['handle'] ?? '')),
                'oauth_scopes' => (string) (config('shopify.oauth_scopes', '') ?: ($app['oauth_scopes'] ?? '')),
            ];
        }

        return [
            'api_key' => (string) ($app['api_key'] ?? ''),
            'api_secret' => (string) ($app['api_secret'] ?? ''),
            'handle' => (string) ($app['handle'] ?? ''),
            'oauth_scopes' => (string) ($app['oauth_scopes'] ?? ''),
        ];
    }

    /** The credentials of the app THIS shop was installed through. */
    public static function forShop(Shop $shop): array
    {
        return self::credentials(self::normalize($shop->shopifyAppKey()));
    }

    /**
     * Which of the app's REQUESTED scopes this shop has not granted.
     *
     * Shopify collapses implied scopes when it reports a grant: asking for
     * `read_products,write_products` comes back as `write_products` alone. A
     * naive set difference therefore reports a mismatch on every healthy shop —
     * so a granted `write_x` is expanded to cover `read_x` before comparing.
     *
     * Non-empty means the stored access token predates a scope change and must
     * be re-exchanged, or every call needing the new scope will 403.
     *
     * @return list<string>
     */
    public static function missingScopes(string $appKey, ?string $granted): array
    {
        $requested = self::splitScopes(self::credentials($appKey)['oauth_scopes']);
        if ($requested === []) {
            return [];
        }

        $held = [];
        foreach (self::splitScopes((string) $granted) as $scope) {
            $held[$scope] = true;
            if (str_starts_with($scope, 'write_')) {
                $held['read_'.substr($scope, 6)] = true;
            }
        }

        return array_values(array_filter(
            $requested,
            static fn (string $scope): bool => ! isset($held[$scope]),
        ));
    }

    /** @return list<string> */
    private static function splitScopes(string $scopes): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $scopes))));
    }

    /**
     * Every secret a Shopify-signed payload may legitimately carry: each
     * configured app's secret plus the explicit SHOPIFY_WEBHOOK_SECRET (if any).
     * Callers LOOP over these with a timing-safe compare — a match with any one
     * proves Shopify sent it (each secret is known only to Shopify and us).
     *
     * @return list<string> deduped, no empties
     */
    public static function secrets(): array
    {
        $secrets = [(string) config('shopify.webhook_secret', '')];
        foreach (self::keys() as $key) {
            $secrets[] = self::credentials($key)['api_secret'];
        }

        return array_values(array_unique(array_filter($secrets, static fn (string $s): bool => $s !== '')));
    }

    /**
     * Verify an App Bridge session token against EVERY configured app. The JWT
     * `aud` claim equals the minting app's api_key, so exactly one pair can
     * validate — the match identifies both the shop (dest) and the app.
     *
     * @return array{claims: array<string, mixed>, app_key: string}|null
     */
    public static function verifySessionToken(SessionTokenVerifier $verifier, string $jwt): ?array
    {
        foreach (self::keys() as $key) {
            $creds = self::credentials($key);
            $claims = $verifier->verify($jwt, $creds['api_secret'], $creds['api_key']);
            if ($claims !== null) {
                return ['claims' => $claims, 'app_key' => $key];
            }
        }

        return null;
    }

    /**
     * Which secret (if any) signed this OAuth-callback query string. Returns the
     * matching app key, or null when no configured app verifies (fail closed).
     */
    public static function matchQueryHmac(array $query): ?string
    {
        foreach (self::keys() as $key) {
            if (ShopifyDomain::verifyQueryHmac($query, self::credentials($key)['api_secret'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The App Bridge api key to embed for a given shop domain: the key of the app
     * that installed the shop, or the public default when the shop is unknown
     * (first-ever load — the managed install resolves the app from the token).
     */
    public static function apiKeyForShopDomain(string $shopDomain): string
    {
        $domain = ShopifyDomain::normalize($shopDomain);
        if ($domain !== '') {
            $shop = Shop::query()->where('shopify_domain', $domain)->first();
            if ($shop !== null) {
                return self::forShop($shop)['api_key'];
            }
        }

        return self::credentials(self::PUBLIC)['api_key'];
    }
}
