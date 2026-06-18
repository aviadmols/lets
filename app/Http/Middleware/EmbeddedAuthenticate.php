<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Models\User;
use App\Services\Shopify\SessionTokenVerifier;
use App\Services\Shopify\ShopifyTokenExchange;
use App\Services\Shopify\ShopInstaller;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * EMBEDDED-ADMIN auth seam for the Filament panel (managed install + App Bridge).
 *
 * The embedded admin loads inside Shopify Admin in an iframe; App Bridge attaches a
 * session token (Authorization: Bearer <jwt>, or ?id_token=<jwt> on the first load).
 * This middleware runs BEFORE Filament's Authenticate on the panel and, for a
 * request that carries a VALID session token:
 *
 *   1. Verifies the token (HS256 w/ the app secret, aud == api_key) and derives the
 *      shop ONLY from the verified `dest` claim — never from any client input.
 *   2. Loads the Shop. If missing or not live, performs MANAGED INSTALL: exchange
 *      the session token for an offline access token (ShopifyTokenExchange) and run
 *      the shared install routine (ShopInstaller) to create/refresh the Shop row,
 *      provision the shop-scoped admin user, register webhooks, and backfill products.
 *   3. Auth::login() the shop's merchant user (looked up by shop_id) so Filament's
 *      Authenticate passes, and Tenant::set() so BindTenantFromUser respects the
 *      already-bound, verified shop (its Tenant::check() short-circuit).
 *
 * FAIL OPEN TO THE LOGIN, NOT 401: unlike SessionTokenAuth (which 401s API-style
 * routes), this middleware must NEVER 401 the panel load. A request with no token,
 * an invalid token, or a failed exchange simply PASSES THROUGH unauthenticated, so
 * the panel still serves the normal platform-admin login (non-embedded use stays
 * intact). The only thing this middleware does on the happy path is log the merchant
 * in; on any unhappy path it is a transparent no-op.
 *
 * Tenant-safety (RELEASE BLOCKER): the shop is derived solely from the verified JWT
 * dest claim. A session token for shop A can only ever bind shop A — the lookup,
 * exchange, install, and login all key off that one verified domain.
 */
final class EmbeddedAuthenticate
{
    public function __construct(
        private readonly SessionTokenVerifier $verifier,
        private readonly ShopifyTokenExchange $tokenExchange,
        private readonly ShopInstaller $installer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $this->extractToken($request);
        if ($jwt === '') {
            // No embedded session token → not an embedded load. Leave the request
            // untouched so the panel's normal (platform-admin) login flow runs.
            return $next($request);
        }

        $claims = $this->verifier->verify(
            $jwt,
            (string) config('shopify.api_secret'),
            (string) config('shopify.api_key'),
        );
        if ($claims === null) {
            // Invalid/expired/foreign token → do NOT 401 the panel; pass through
            // unauthenticated so non-embedded login still works.
            return $next($request);
        }

        $shopDomain = $this->verifier->shopDomainFromClaims($claims);
        if ($shopDomain === '') {
            return $next($request);
        }

        $shop = $this->resolveOrInstallShop($shopDomain, $jwt);
        if ($shop === null) {
            // Missing/not-live shop and the managed-install exchange failed → pass
            // through unauthenticated (the iframe will re-auth / retry).
            return $next($request);
        }

        $user = $this->merchantUserFor($shop);
        if ($user === null) {
            // Should not happen (install provisions a user), but never bind a tenant
            // without a login — fail closed by passing through unauthenticated.
            return $next($request);
        }

        // Log the shop's merchant user in (Filament's Authenticate now passes) and
        // bind the verified shop as tenant so BindTenantFromUser respects it.
        Auth::login($user);
        Tenant::set($shop);

        try {
            return $next($request);
        } finally {
            // Long-lived workers (Octane/FrankPHP) share a process — clear so the
            // next request never inherits this shop. The user stays logged in via the
            // session for subsequent same-session requests; the tenant rebinds from
            // the user (BindTenantFromUser) or a fresh session token each request.
            Tenant::clear();
        }
    }

    /**
     * Load the live Shop for the verified domain, or run the managed-install
     * token-exchange to create/refresh it. Returns null if no live shop can be
     * obtained (exchange failed) — the caller then passes through unauthenticated.
     */
    private function resolveOrInstallShop(string $shopDomain, string $sessionToken): ?Shop
    {
        $shop = Shop::query()->where('shopify_domain', $shopDomain)->first();
        if ($shop !== null && $shop->isLive()) {
            // Already installed + live → no second exchange needed.
            return $shop;
        }

        // Missing or not live (uninstalled / not yet captured) → managed install:
        // exchange the verified session token for an offline token, then install.
        $exchanged = $this->tokenExchange->exchange($shopDomain, $sessionToken);
        if ($exchanged === null) {
            return null;
        }

        $shop = $this->installer->installFromToken(
            $shopDomain,
            $exchanged['access_token'],
            $exchanged['scope'] !== '' ? $exchanged['scope'] : null,
        );

        Log::info('shopify.embedded.managed_install', ['shop' => $shopDomain]);

        // installFromToken sets status INSTALLED ⇒ live; re-read defensively.
        return $shop->isLive() ? $shop : null;
    }

    /**
     * The shop-scoped admin login to authenticate as. Looked up by shop_id (the
     * exact link MerchantUserProvisioner created), so a session token for shop A can
     * only ever log in shop A's user. Returns null if none is linked.
     */
    private function merchantUserFor(Shop $shop): ?User
    {
        return User::query()->where('shop_id', $shop->getKey())->first();
    }

    private function extractToken(Request $request): string
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer !== '') {
            return $bearer;
        }

        // First embedded load arrives with ?id_token=… before App Bridge can set the
        // Authorization header.
        return (string) $request->query('id_token', '');
    }
}
