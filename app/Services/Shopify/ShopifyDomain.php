<?php

namespace App\Services\Shopify;

/**
 * Shop-domain validation + OAuth-query HMAC. Pure, stateless helpers used by the
 * OAuth flow and App Proxy. The myshopify.com regex is the FIRST gate on every
 * shop-supplied domain — an attacker-controlled `shop` param must never reach an
 * outbound HTTP call (SSRF) or a Shop lookup.
 */
final class ShopifyDomain
{
    // === CONSTANTS ===
    /** A valid permanent shop domain: lowercase, ends in .myshopify.com. */
    public const DOMAIN_REGEX = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

    /** Normalise + validate a shop domain. Returns '' when invalid. */
    public static function normalize(string $shop): string
    {
        $shop = strtolower(trim($shop));

        // Strip a scheme / path if the caller passed a URL.
        $shop = preg_replace('#^https?://#', '', $shop) ?? $shop;
        $shop = explode('/', $shop)[0];

        return self::isValid($shop) ? $shop : '';
    }

    public static function isValid(string $shop): bool
    {
        return preg_match(self::DOMAIN_REGEX, $shop) === 1;
    }

    /**
     * Verify Shopify's OAuth / App-Proxy query HMAC: sorted params (hmac +
     * signature removed), joined as k=v&…, HMAC-SHA256 with the app secret, hex,
     * timing-safe compare. Per Shopify docs.
     *
     * @param  array<string, mixed>  $query
     */
    public static function verifyQueryHmac(array $query, string $secret, string $hmacKey = 'hmac'): bool
    {
        $provided = $query[$hmacKey] ?? null;
        if ($secret === '' || ! is_string($provided) || $provided === '') {
            return false;
        }

        unset($query[$hmacKey], $query['signature']);
        ksort($query);

        $pairs = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $pairs[] = $key.'='.$value;
        }

        $calculated = hash_hmac('sha256', implode('&', $pairs), $secret);

        return hash_equals($calculated, $provided);
    }
}
