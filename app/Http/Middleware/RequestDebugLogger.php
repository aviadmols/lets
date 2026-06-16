<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORARY request tracer. Logs every inbound request (except the /up health
 * check) to the `stderr` channel so it surfaces in `railway logs`. Purpose:
 * prove whether a browser request that displays 403 actually reaches this app —
 * and, if it does, what status WE return. If the request never appears in the
 * logs, the 403 is injected before Railway (extension / network / filter).
 * Remove once the 403 source is identified.
 */
class RequestDebugLogger
{
    // === CONSTANTS ===
    private const TAG = 'REQ-DEBUG';
    private const SKIP_PATHS = ['up'];   // Railway health-check noise
    private const UA_MAX = 180;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $path = $request->path();
        if (! in_array($path, self::SKIP_PATHS, true)) {
            Log::channel('stderr')->info(self::TAG, [
                'method' => $request->getMethod(),
                'host'   => $request->getHost(),
                'path'   => '/'.ltrim($path, '/'),
                'status' => $response->getStatusCode(),
                'scheme' => $request->isSecure() ? 'https' : 'http',
                'ip'     => $request->ip(),
                'xff'    => $request->header('X-Forwarded-For'),
                'xproto' => $request->header('X-Forwarded-Proto'),
                'ref'    => $request->header('referer'),
                'ua'     => substr((string) $request->userAgent(), 0, self::UA_MAX),
            ]);
        }

        return $response;
    }
}
