<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * CORS for the checkout / customer-account UI extension fetch ONLY.
 *
 * Those extensions (purchase.thank-you.block.render,
 * customer-account.order-status.block.render) run in a Shopify-controlled
 * sandboxed worker origin (e.g. https://extensions.shopifycdn.com), so a direct
 * fetch to https://app.lets.co.il is CROSS-ORIGIN. The browser blocks the response
 * unless the app returns `Access-Control-Allow-Origin: *` and answers the OPTIONS
 * preflight allowing the `Authorization` (session-token bearer) + `Accept` headers.
 *
 * SCOPE: this middleware is attached ONLY to the upsell offer + accept-api routes
 * (the extension seam). It is NOT global — admin / OAuth / webhook routes must keep
 * their default same-origin posture (opening CORS there would be a security
 * regression). The request is STILL authenticated by the route's own auth (the
 * session-token middleware or the URL signature); CORS only governs whether the
 * browser exposes the response to the extension — it is not an auth bypass.
 *
 * `*` (not a reflected origin) is correct here because the requests are NOT
 * credentialed (no cookies; the auth is a bearer token / signed URL), and the
 * extension worker origin is not a fixed, enumerable value.
 */
final class AllowExtensionCors
{
    // === CONSTANTS ===
    /** Any origin — the extension worker origin is Shopify-controlled, not fixed. */
    private const ALLOW_ORIGIN = '*';
    /** Verbs the extension uses: GET (offer), POST (accept), OPTIONS (preflight). */
    private const ALLOW_METHODS = 'GET, POST, OPTIONS';
    /** Request headers the extension sends: bearer token + content negotiation. */
    private const ALLOW_HEADERS = 'Authorization, Accept, Content-Type';
    /** Cache the preflight result (seconds) so the browser need not re-ask often. */
    private const MAX_AGE = '86400';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Answer the preflight without touching the controller (auth/body run on the
        // real request). 204 No Content is the canonical preflight response.
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCors(new Response('', Response::HTTP_NO_CONTENT));
        }

        return $this->withCors($next($request));
    }

    private function withCors(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Access-Control-Allow-Origin', self::ALLOW_ORIGIN);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        $response->headers->set('Access-Control-Max-Age', self::MAX_AGE);
        // Tell shared caches the response varies by Origin (good hygiene even at `*`).
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
