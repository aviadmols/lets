<?php

namespace App\Services\Shopify;

use Illuminate\Http\Request;

/**
 * WHICH customer is behind a session-token request.
 *
 * `shopify.session` (SessionTokenAuth) verifies the JWT and binds the SHOP —
 * but not the shopper. On customer-account surfaces the token's `sub` claim is
 * the logged-in customer (a Customer GID, or the bare numeric id); on admin
 * surfaces it is a staff user id with no Customer meaning. This helper refuses
 * any admin-shaped token (iss/dest on the /admin path) outright and accepts only
 * a sub that resolves under the Customer GID namespace, so an admin token can
 * never impersonate a shopper — fail closed, not open.
 *
 * Extracted from CustomerContractController so every customer-account endpoint
 * (contracts, the personal area) resolves identity through ONE door.
 */
final class SessionTokenCustomer
{
    // === CONSTANTS ===
    private const CUSTOMER_GID_PREFIX = 'gid://shopify/Customer/';

    /**
     * An EMBEDDED-ADMIN (App Bridge) token names its shop as https://{shop}/admin;
     * a customer-account / checkout extension token names the bare host. Its sub
     * is a STAFF user id, never a customer.
     */
    private const ADMIN_CLAIM_PATTERN = '#^https?://[^/]+/admin(?:[/?\#]|$)#i';

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

        // An admin token is never a shopper, whatever its sub looks like: a staff
        // user whose numeric id happened to equal a customer's would otherwise
        // act on that customer's subscriptions.
        if (self::isAdminToken($claims)) {
            return null;
        }

        if (str_starts_with($sub, self::CUSTOMER_GID_PREFIX)) {
            return $sub;
        }

        // A customer-account token may carry the bare numeric id (admin tokens
        // were refused above, so a number here is a customer's).
        return ctype_digit($sub) ? self::CUSTOMER_GID_PREFIX.$sub : null;
    }

    /** @param  array<string, mixed>  $claims */
    private static function isAdminToken(array $claims): bool
    {
        foreach (['iss', 'dest'] as $claim) {
            if (preg_match(self::ADMIN_CLAIM_PATTERN, (string) ($claims[$claim] ?? '')) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The bare numeric id inside a Customer GID ("gid://shopify/Customer/42" → "42"). */
    public static function numericId(string $customerGid): string
    {
        return str_starts_with($customerGid, self::CUSTOMER_GID_PREFIX)
            ? substr($customerGid, strlen(self::CUSTOMER_GID_PREFIX))
            : $customerGid;
    }
}
