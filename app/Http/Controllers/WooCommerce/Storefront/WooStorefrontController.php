<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Http\Middleware\VerifyWooCommerceSignature;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Base for the WooCommerce storefront endpoints (the WC analogue of the Shopify
 * App-Proxy ProxyInstallmentController). The verified shop is read SOLELY from the
 * VerifyWooCommerceSignature middleware's request attribute — the HMAC-bound tenant —
 * never from the request body, a header alone, or a session. A storefront request that
 * reaches a controller method has already passed the per-shop HMAC, so the shop here is
 * always present + trustworthy; we still guard so a misconfigured route can't leak.
 *
 * Tenant law: the shop is the HMAC-verified shop ONLY. The plugin server signs every
 * call with the connection api_secret (the shopper's browser never holds it); the
 * SaaS resolves the shop by sha256(api_key) and binds the tenant before this runs.
 */
abstract class WooStorefrontController
{
    /** The HMAC-verified shop the middleware bound, or null (defensive — never expected). */
    protected function verifiedShop(Request $request): ?Shop
    {
        $shop = $request->attributes->get(VerifyWooCommerceSignature::ATTR_SHOP);

        return $shop instanceof Shop ? $shop : null;
    }

    /** Trim + bound a free-text storefront string, or null when empty. */
    protected function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    /** A valid email or null (never trust an unvalidated address into a plan). */
    protected function cleanEmail(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
