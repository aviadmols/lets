<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\OfferResponder;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Shopify\SessionTokenVerifier;
use App\Services\Shopify\ShopifyApps;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET "eligible upsell offer" for the thank-you + order-status UI extensions,
 * authed by the App Bridge SESSION TOKEN (not the App Proxy).
 *
 * Why a session-token twin of ProxyOfferController exists: checkout / customer-
 * account UI extensions (purchase.thank-you.block.render,
 * customer-account.order-status.block.render) run in a sandboxed web worker that
 * does NOT share the storefront origin/session, so a RELATIVE /apps/payplus/...
 * App-Proxy fetch cannot resolve. Shopify's guidance for those targets is a DIRECT
 * fetch to an absolute app URL authenticated with a session-token (JWT) bearer.
 * The session-token API (shopify.sessionToken / shopify.idToken) is available in
 * BOTH targets, and the JWT is the same HS256-app-secret family the embedded admin
 * already verifies.
 *
 * Auth + tenant: SessionTokenAuth (the `shopify.session` middleware) has ALREADY
 * verified the JWT (signature, aud == api_key, exp/nbf, iss == dest), resolved the
 * Shop from the `dest` claim, asserted it is live, and bound it as the Tenant. So
 * here we trust the BOUND tenant — never a shop id from client input. A missing /
 * invalid token never reaches this controller (the middleware returns 401).
 *
 * Response shape is IDENTICAL to ProxyOfferController (both delegate to
 * OfferResponder): the offer JSON with the SERVER-computed price plus an ABSOLUTE
 * signed `accept_api_url` the extension POSTs to. Money stays server-side; the
 * client sends no shop id and no amount.
 */
final class SessionTokenOfferController extends Controller
{
    // === CONSTANTS ===
    /** Only a Customer gid in `sub` names a shopper; a staff token's bare id never does. */
    private const CUSTOMER_GID_PREFIX = 'gid://shopify/Customer/';

    public function __construct(
        private readonly OfferResponder $responder,
        private readonly SessionTokenVerifier $verifier,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Shop|null $shop SessionTokenAuth bound this from the verified JWT. */
        $shop = Tenant::current();

        // Defence in depth: the middleware guarantees a bound live shop; if it is
        // somehow absent, fail closed rather than resolve an offer for no tenant.
        if (! $shop instanceof Shop || Tenant::id() !== (int) $shop->getKey()) {
            return response()->json(['offer' => null, 'reason' => 'no_tenant'], 200);
        }

        // The shared responder verifies the order is the token's shopper's, resolves
        // under the bound tenant (recording the impression), and shapes the offer
        // JSON + signed URLs.
        return response()->json($this->responder->respond($request, $shop, $this->verifiedCustomerId($request, $shop)), 200);
    }

    /**
     * The shopper the session token proves: its `sub`, which Shopify sets to the
     * buyer's Customer gid when they are logged in. The `customer` query param is
     * never consulted — any shopper's token would otherwise mint a charge link for
     * anyone. Re-verified here (the middleware verified it but keeps no claims), and
     * the token must be for the SAME shop the middleware bound.
     */
    private function verifiedCustomerId(Request $request, Shop $shop): string
    {
        // The same two places SessionTokenAuth reads the token from.
        $jwt = (string) ($request->bearerToken() ?: $request->query('id_token', ''));
        $verified = $jwt !== '' ? ShopifyApps::verifySessionToken($this->verifier, $jwt) : null;
        if ($verified === null
            || $this->verifier->shopDomainFromClaims($verified['claims']) !== (string) $shop->shopify_domain) {
            return '';
        }

        $sub = (string) ($verified['claims']['sub'] ?? '');
        if (! str_starts_with($sub, self::CUSTOMER_GID_PREFIX)) {
            return '';
        }

        $id = substr($sub, strlen(self::CUSTOMER_GID_PREFIX));

        return ctype_digit($id) ? $id : '';
    }
}
