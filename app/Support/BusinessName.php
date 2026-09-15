<?php

namespace App\Support;

use App\Models\Shop;

/**
 * The merchant-facing store name used in email copy and the From name.
 *
 * One source of truth so every surface signs off as the same store: the mailables
 * (through ResolvesBusinessName, which delegates here) and the admin's email
 * preview, which must show the name the customer actually received.
 *
 * Ladder: the merchant's own `business_name` (Settings → Customer area) → the
 * installer-written `name` → the Shopify handle → a neutral fallback.
 *
 * Multi-tenant: read from the given shop, never from global config — a worker that
 * just handled shop B must not sign shop A's mail.
 */
final class BusinessName
{
    // === CONSTANTS ===
    /** Final fallback when a shop has no name yet. */
    public const FALLBACK = 'Our Store';

    public static function for(?Shop $shop): string
    {
        if ($shop === null) {
            return (string) config('app.name', self::FALLBACK);
        }

        // The name the merchant typed wins over the one an installer wrote —
        // which, for a store connected by domain, is just the domain.
        foreach ([$shop->business_name ?? null, $shop->name ?? null] as $candidate) {
            $name = trim((string) $candidate);
            if ($name !== '') {
                return $name;
            }
        }

        $domain = trim((string) ($shop->shopify_domain ?? ''));
        if ($domain !== '') {
            // "acme.myshopify.com" → "acme"
            return ucfirst((string) strtok($domain, '.'));
        }

        return self::FALLBACK;
    }
}
