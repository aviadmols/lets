<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends HTTP Strict-Transport-Security so browsers pin https for this host and
 * never silently fall back to http (which previously hit the stale uPress vhost
 * and returned 403). Behind Railway's TLS-terminating proxy, isSecure() is true
 * only because trustProxies (bootstrap/app.php) trusts X-Forwarded-Proto.
 */
class AddHstsHeader
{
    // === CONSTANTS ===
    // 1 year. Deliberately NO `includeSubDomains` and NO `preload`: sibling
    // *.lets.co.il hosts (uPress) may still serve plain HTTP, and includeSubDomains
    // would force https on ALL of them and break them. This pins ONLY the exact
    // host that emits the header (app.lets.co.il).
    private const HSTS_VALUE = 'max-age=31536000';
    private const HEADER = 'Strict-Transport-Security';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only advertise HSTS over a genuine https request in production. Browsers
        // ignore HSTS received over http anyway, and we must never pin https in
        // local dev (http://localhost) or we'd lock ourselves out.
        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set(self::HEADER, self::HSTS_VALUE);
        }

        return $response;
    }
}
