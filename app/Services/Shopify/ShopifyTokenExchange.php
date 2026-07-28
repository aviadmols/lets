<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Token Exchange (RFC 8693) for Shopify MANAGED INSTALL.
 *
 * With managed install (use_legacy_install_flow = false in shopify.app.toml),
 * Shopify performs the authorize step itself — the app never runs the redirect
 * grant. Instead, on the first embedded load App Bridge hands us a session token
 * (a short-lived id_token JWT), and we exchange THAT for a long-lived OFFLINE
 * access token by POSTing the token-exchange grant to the shop's token endpoint.
 *
 * We request an OFFLINE-access token (not online) so background billing/sync run
 * with no user present — the same token kind the legacy callback captured, and
 * an EXPIRING one (`expiring=1`), because Shopify no longer accepts the other
 * kind on the Admin API. Asking is the only way to get one; there is no setting.
 *
 * Fail closed: any non-2xx, missing token, or transport error returns null (the
 * caller then leaves the request unauthenticated). The session token MUST already
 * be verified by SessionTokenVerifier before calling this — we never exchange an
 * unverified subject token, and the shop domain comes ONLY from the verified claims.
 */
final class ShopifyTokenExchange
{
    // === CONSTANTS ===
    /** OAuth 2.0 Token Exchange grant type (RFC 8693). */
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';
    /** The subject token is a Shopify session token (an id_token / OIDC JWT). */
    private const SUBJECT_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';
    /** We want a long-lived OFFLINE access token (background work, no user present). */
    private const REQUESTED_TOKEN_TYPE = 'urn:shopify:params:oauth:token-type:offline-access-token';
    /** HTTP timeout for the exchange call (seconds). */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Exchange a verified session token for an offline access token.
     *
     * @param  string  $shopDomain    a validated *.myshopify.com domain (from verified claims)
     * @param  string  $sessionToken  the verified App Bridge session token (subject)
     * @param  string  $appKey        the Partner app whose session token this is (its `aud`)
     * @return ShopifyToken|null  null on any failure (fail closed)
     */
    public function exchange(string $shopDomain, string $sessionToken, string $appKey = ShopifyApps::PUBLIC): ?ShopifyToken
    {
        $app = ShopifyApps::credentials($appKey);
        $clientId = $app['api_key'];
        $clientSecret = $app['api_secret'];
        if ($clientId === '' || $clientSecret === '' || $shopDomain === '' || $sessionToken === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asForm()
                ->post(sprintf('https://%s/admin/oauth/access_token', $shopDomain), [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => self::GRANT_TYPE,
                    'subject_token' => $sessionToken,
                    'subject_token_type' => self::SUBJECT_TOKEN_TYPE,
                    'requested_token_type' => self::REQUESTED_TOKEN_TYPE,
                    // Without this Shopify mints a NON-EXPIRING token, which the
                    // Admin API rejects with 403 on the very first call — the
                    // shop installs "successfully" and is dead on arrival.
                    ...ShopifyToken::expiringParams(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('shopify.token_exchange.error', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('shopify.token_exchange.failed', [
                'shop' => $shopDomain,
                'status' => $response->status(),
            ]);

            return null;
        }

        $token = ShopifyToken::fromResponse((array) $response->json());
        if ($token === null) {
            Log::warning('shopify.token_exchange.no_token', ['shop' => $shopDomain]);

            return null;
        }

        // We asked for an expiring token; if one did not come back, the app is
        // about to store a token the Admin API will refuse. Say so — the previous
        // symptom was a silent 403 on every call, days later.
        if (! $token->isExpiring()) {
            Log::error('shopify.token_exchange.non_expiring', [
                'shop' => $shopDomain,
                'app' => $appKey,
            ]);
        }

        return $token;
    }
}
