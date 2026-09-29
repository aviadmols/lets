<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed URL on this route must carry an expiry.
 *
 * Laravel's `signed` middleware accepts a URL signed WITHOUT `expires` as valid
 * forever. For a route whose signature is the whole credential (the loyalty
 * club's WooCommerce actions — redeem points, claim a perk) that is a standing
 * bearer token, and every such link minted before expiries were added would
 * keep working. Run AFTER `signed`: the signature proves `expires` was not
 * edited; this proves it is there at all.
 */
final class RequireSignatureExpiry
{
    // === CONSTANTS ===
    public const EXPIRES_PARAM = 'expires';

    public function handle(Request $request, Closure $next): Response
    {
        $expires = (string) $request->query(self::EXPIRES_PARAM, '');

        if ($expires === '' || ! ctype_digit($expires)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
