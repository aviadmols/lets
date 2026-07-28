<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Shopify\SessionTokenVerifier;
use App\Services\Shopify\ShopifyApps;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            return $this->unauthenticated($request, 'no_token');
        }

        // Try every configured Partner app — the JWT `aud` pins which one minted it.
        $verified = ShopifyApps::verifySessionToken($this->verifier, $jwt);
        if ($verified === null) {
            return $this->unauthenticated($request, 'verify_failed', $jwt);
        }

        $shopDomain = $this->verifier->shopDomainFromClaims($verified['claims']);
        $shop = Shop::query()->where('shopify_domain', $shopDomain)->first();
        if ($shop === null || ! $shop->isLive()) {
            // Not installed / uninstalled / not yet subscribed → re-auth or
            // re-subscribe via the OAuth + saas billing flow.
            return $this->unauthenticated($request, $shop === null ? 'no_shop' : 'shop_not_live');
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

    /**
     * Refuse, and SAY WHICH of the three gates closed.
     *
     * A bare 401 here is indistinguishable from "no offer for this order" once it
     * reaches an extension that fails closed and renders nothing — the widget goes
     * blank either way, and the request log shows a handled request with zero
     * queries. Naming the gate is what separates "the extension sent no token"
     * from "the token is for another app" from "this shop is not installed".
     *
     * The token is never logged. Only its unverified `aud`/`dest` claims are, and
     * only when verification failed — that is precisely the pair that says whether
     * a token was minted for an app this deployment does not know.
     */
    private function unauthenticated(Request $request, string $reason, string $jwt = ''): Response
    {
        Log::info('shopify.session_token.rejected', array_filter([
            'reason' => $reason,
            'path' => $request->path(),
            'claims' => $jwt !== '' ? $this->unverifiedClaims($jwt) : null,
        ], static fn ($v): bool => $v !== null));

        // 401 with the App-Bridge re-auth header so the iframe knows to fetch a
        // fresh session token (or bounce through OAuth).
        return response()->json(['status' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED)
            ->header('X-Shopify-API-Request-Failure-Reauthorize', '1');
    }

    /**
     * The `aud` and `dest` of a token we could NOT verify, read without trusting
     * them. Diagnostics only — nothing downstream may act on an unverified claim,
     * which is why this returns strings for a log line and never a Shop.
     *
     * @return array<string, string>
     */
    private function unverifiedClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return ['malformed' => 'not_a_jwt'];
        }

        $payload = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/'), true),
            true,
        );

        return is_array($payload)
            ? ['aud' => (string) ($payload['aud'] ?? ''), 'dest' => (string) ($payload['dest'] ?? '')]
            : ['malformed' => 'undecodable_payload'];
    }
}
