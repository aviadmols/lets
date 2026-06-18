<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\OfferResponder;
use App\Http\Controllers\Controller;
use App\Models\Shop;
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
    public function __construct(private readonly OfferResponder $responder) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Shop|null $shop SessionTokenAuth bound this from the verified JWT. */
        $shop = Tenant::current();

        // Defence in depth: the middleware guarantees a bound live shop; if it is
        // somehow absent, fail closed rather than resolve an offer for no tenant.
        if (! $shop instanceof Shop || Tenant::id() !== (int) $shop->getKey()) {
            return response()->json(['offer' => null, 'reason' => 'no_tenant'], 200);
        }

        // The shared responder builds the context, resolves under the bound tenant
        // (recording the impression), and shapes the offer JSON + signed URLs.
        return response()->json($this->responder->respond($request, $shop), 200);
    }
}
