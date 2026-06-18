<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remember the Shopify EMBEDDED context (the `host`) across the server-rendered
 * admin so App Bridge can be loaded on every page of an embedded session.
 *
 * Shopify appends `?host=…` (+ `embedded=1`, `shop=…`, `id_token=…`) to the App
 * URL on the FIRST embedded load. Filament navigates full-page, and those internal
 * links may not carry `host`, so we persist it in the session here. The App-Bridge
 * head partial (resources/views/filament/embedded/app-bridge.blade.php) reads
 * `shopify_embedded` to decide whether to load App Bridge.
 *
 * Why session-gated, not always-on: App Bridge MUST NOT be loaded outside the
 * Shopify iframe — with no host it redirects the page INTO Shopify, which would
 * hijack the non-embedded platform-admin login (app.lets.co.il/admin direct). The
 * platform admin's session never has `host`, so it never gets the flag, so it never
 * loads App Bridge. The flag is per-session (per cookie), so it cannot leak between
 * an embedded merchant and a direct platform-admin login.
 */
final class PersistEmbeddedContext
{
    // === CONSTANTS ===
    public const SESSION_HOST = 'shopify_host';
    public const SESSION_SHOP = 'shopify_shop';
    public const SESSION_EMBEDDED = 'shopify_embedded';

    public function handle(Request $request, Closure $next): Response
    {
        $host = (string) $request->query('host', '');
        if ($host !== '') {
            $request->session()->put(self::SESSION_HOST, $host);
            $request->session()->put(self::SESSION_EMBEDDED, true);

            $shop = (string) $request->query('shop', '');
            if ($shop !== '') {
                $request->session()->put(self::SESSION_SHOP, $shop);
            }
        }

        return $next($request);
    }
}
