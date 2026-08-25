<?php

namespace App\Services\Shopify;

use Illuminate\Http\Request;

/**
 * WHICH customer is behind a session-token request.
 *
 * `shopify.session` (SessionTokenAuth) verifies the JWT and binds the SHOP —
 * but not the shopper. On customer-account surfaces the token's `sub` claim is
 * the logged-in customer (a Customer GID, or the bare numeric id); on admin
 * surfaces it is a staff user id with no Customer meaning. This helper accepts
 * only a sub that resolves under the Customer GID namespace, so an admin token
 * can never impersonate a shopper — fail closed, not open.
 *
 * Extracted from CustomerContractController so every customer-account endpoint
 * (contracts, the personal area) resolves identity through ONE door.
 */
final class SessionTokenCustomer
{
    // === CONSTANTS ===
    private const CUSTOMER_GID_PREFIX = 'gid://shopify/Customer/';

    public function __construct(private readonly SessionTokenVerifier $verifier) {}

    /** The logged-in customer's GID from the request's bearer token, or null. */
    public function gidFromRequest(Request $request): ?string
    {
        $jwt = trim(str_ireplace('Bearer', '', (string) $request->header('Authorization', '')));
        if ($jwt === '') {
            return null;
        }

        // Any configured Partner app's token is acceptable — the `aud` pins the app.
        $verified = ShopifyApps::verifySessionToken($this->verifier, $jwt);
        $claims = $verified !== null ? $verified['claims'] : [];

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            return null;
        }

        if (str_starts_with($sub, self::CUSTOMER_GID_PREFIX)) {
            return $sub;
        }

        // Customer-account tokens may carry the bare numeric id.
        return ctype_digit($sub) ? self::CUSTOMER_GID_PREFIX.$sub : null;
    }

    /** The bare numeric id inside a Customer GID ("gid://shopify/Customer/42" → "42"). */
    public static function numericId(string $customerGid): string
    {
        return str_starts_with($customerGid, self::CUSTOMER_GID_PREFIX)
            ? substr($customerGid, strlen(self::CUSTOMER_GID_PREFIX))
            : $customerGid;
    }
}
