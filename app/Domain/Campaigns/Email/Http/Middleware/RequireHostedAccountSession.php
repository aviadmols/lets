<?php

namespace App\Domain\Campaigns\Email\Http\Middleware;

use App\Domain\Campaigns\Email\Http\HostedAccountSession;
use App\Models\Shop;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hosted personal area's front door: a live HostedAccountSession, or a
 * 410 page. Binds the tenant to the session's shop for the request, and
 * pushes the idle deadline.
 *
 * Fail-closed: no bag, an expired bag, a shop that no longer exists or is not
 * live — all the same page, which says only that the link has run its course.
 */
final class RequireHostedAccountSession
{
    // === CONSTANTS ===
    public const VIEW_EXPIRED = 'campaigns.login-expired';

    public function __construct(private readonly HostedAccountSession $hosted) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = $this->hosted->shop();

        if (! $shop instanceof Shop || ! $shop->isLive()) {
            $this->hosted->end();

            return response()->view(self::VIEW_EXPIRED, [], Response::HTTP_GONE);
        }

        $this->hosted->touch();

        return Tenant::run($shop, static fn (): Response => $next($request));
    }
}
