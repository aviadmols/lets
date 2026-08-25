<?php

namespace App\Domain\Campaigns\Email\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every public campaign page (login landing, hosted area,
 * unsubscribe) answers with.
 *
 * The URL of the landing page IS a credential until it is spent, so: no
 * referrer leaks it to the next site, no cache keeps a copy, no crawler indexes
 * it. Set here, once, rather than in each controller — a page added later
 * inherits the rule by being in the route group.
 */
final class CampaignPublicHeaders
{
    // === CONSTANTS ===
    public const HEADERS = [
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
