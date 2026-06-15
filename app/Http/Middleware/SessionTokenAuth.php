<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Shopify\SessionTokenVerifier;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates embedded-admin requests via the App Bridge session token and
 * binds the matching Shop as Tenant for the request lifetime (cleared after).
 *
 * The embedded Filament admin loads inside Shopify Admin in an iframe; App Bridge
 * attaches a session JWT to each request (Authorization: Bearer <jwt>, or
 * ?id_token=<jwt> on first load). We verify it (HS256 w/ the app secret), derive
 * the shop from the dest claim, load the Shop, assert it is live, and bind it.
 *
 * This is the EMBEDDED-UI auth seam. admin-design-system's AdminPanelProvider
 * consumes it by registering this middleware on the Filament panel's middleware
 * stack (see docs in the final report); after it runs, Tenant::current() is the
 * authenticated shop and BelongsToShop scopes every admin query automatically.
 */
final class SessionTokenAuth
{
    public function __construct(private readonly SessionTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $this->extractToken($request);
        if ($jwt === '') {
            return $this->unauthenticated();
        }

        $claims = $this->verifier->verify(
            $jwt,
            (string) config('shopify.api_secret'),
            (string) config('shopify.api_key'),
        );
        if ($claims === null) {
            return $this->unauthenticated();
        }

        $shopDomain = $this->verifier->shopDomainFromClaims($claims);
        $shop = Shop::query()->where('shopify_domain', $shopDomain)->first();
        if ($shop === null || ! $shop->isLive()) {
            // Not installed / uninstalled / not yet subscribed → re-auth or
            // re-subscribe via the OAuth + saas billing flow.
            return $this->unauthenticated();
        }

        Tenant::set($shop);

        try {
            return $next($request);
        } finally {
            // Web requests share the process under some servers; clear so the
            // next request never inherits this shop. (Jobs use TenantContext.)
            Tenant::clear();
        }
    }

    private function extractToken(Request $request): string
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer !== '') {
            return $bearer;
        }

        // First embedded load arrives with ?id_token=… before App Bridge can set
        // the Authorization header.
        return (string) $request->query('id_token', '');
    }

    private function unauthenticated(): Response
    {
        // 401 with the App-Bridge re-auth header so the iframe knows to fetch a
        // fresh session token (or bounce through OAuth).
        return response()->json(['status' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED)
            ->header('X-Shopify-API-Request-Failure-Reauthorize', '1');
    }
}
