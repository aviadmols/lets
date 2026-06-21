<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-token BOUNCE for the embedded admin. Runs AFTER EmbeddedAuthenticate
 * (which logs the merchant in when a valid session token is present) and BEFORE
 * the panel's Authenticate (which would 302 → /admin/login).
 *
 * If the request is EMBEDDED (Shopify `host` present, or an embedded session) and
 * still UNauthenticated after EmbeddedAuthenticate — a deep-link, a first load,
 * or an EXPIRED session token — we must NOT show the login (a merchant has no
 * password; they authenticate only via the Shopify session token). Instead we
 * return the App-Bridge bounce page (resources/views/shopify/bounce.blade.php),
 * which mints a fresh `id_token` and reloads the same URL with it; the reloaded
 * request then authenticates via EmbeddedAuthenticate.
 *
 * Loop guard: the bounce appends `shopify_bounced=1` to the URL (cookie-free, so
 * it survives even when the iframe cookie is dropped). If a request already
 * carries that marker and is STILL unauthenticated, we do NOT bounce again — we
 * fall through to the normal flow (login / 401), so a genuinely un-authable shop
 * can never spin forever.
 *
 * NON-embedded requests (no host, no embedded session — e.g. the platform admin
 * on app.lets.co.il/admin directly) are untouched: they keep the normal login.
 */
final class EnsureEmbeddedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Already authenticated (EmbeddedAuthenticate logged the merchant in, or a
        // valid session cookie) → nothing to do.
        if (Auth::check()) {
            return $next($request);
        }

        $apiKey = (string) config('shopify.api_key');

        $embedded = $request->filled('host')
            || $request->session()->get(PersistEmbeddedContext::SESSION_EMBEDDED) === true;

        // Only bounce a full-page GET in an embedded context that has not already
        // bounced, and only when App Bridge can actually be configured (api key set).
        if ($embedded
            && $request->isMethod('GET')
            && ! $request->boolean('shopify_bounced')
            && $apiKey !== ''
        ) {
            return response()->view('shopify.bounce', ['apiKey' => $apiKey]);
        }

        return $next($request);
    }
}
