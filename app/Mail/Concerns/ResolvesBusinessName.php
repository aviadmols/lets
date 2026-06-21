<?php

namespace App\Mail\Concerns;

use App\Models\Shop;

/**
 * Resolves the merchant-facing "business name" used in email copy + the From
 * name. Ported from the reference engine's Mail/ResolvesBusinessName: a single
 * source of truth so every mailable signs off as the same store, never as the
 * platform.
 *
 * Multi-tenant: the name is read from the SENDING shop, never from a global
 * config. Falls back through shop name → shopify domain → platform app name so a
 * partially-onboarded shop still produces a sensible signature.
 */
trait ResolvesBusinessName
{
    // === CONSTANTS ===
    /** Final fallback when a shop has no name yet. */
    private const FALLBACK_BUSINESS_NAME = 'Our Store';

    protected function resolveBusinessName(?Shop $shop): string
    {
        if ($shop === null) {
            return (string) config('app.name', self::FALLBACK_BUSINESS_NAME);
        }

        $name = trim((string) ($shop->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $domain = trim((string) ($shop->shopify_domain ?? ''));
        if ($domain !== '') {
            // "acme.myshopify.com" → "acme"
            return ucfirst((string) strtok($domain, '.'));
        }

        return self::FALLBACK_BUSINESS_NAME;
    }
}
