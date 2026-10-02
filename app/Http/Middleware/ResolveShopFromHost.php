<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\ShopHandleResolver;
use App\Domain\Tenancy\ShopHosts;
use App\Support\RequestedShop;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * FIRST in the admin panel's middleware (and Livewire-persistent, so every
 * /livewire/update re-runs it): read the shop the HOST names.
 *
 *   - `app.lets.co.il` (the root) or any other trusted host → no requested
 *     shop; the panel behaves exactly as before (embedded flows included).
 *   - `<handle>.app.lets.co.il`, a current handle → RequestedShop is set.
 *   - an ALIAS (an old handle, still within its 30 days) → 301 to the same path
 *     on the shop's current host.
 *   - anything else under the root (unknown, reserved, nested) → a plain 404
 *     "no such store" page. Never the login form.
 *
 * Only the Host header is read (X-Forwarded-Host is not trusted — see
 * bootstrap/app.php), and only `shops.handle` can answer it: no shop id is ever
 * taken from the request. Off entirely while SHOP_SUBDOMAINS_ENABLED is false.
 *
 * Responses are THROWN (HttpResponseException), not returned: Livewire re-runs
 * persistent middleware through a pipeline whose return value it discards, so
 * a returned 404 would be silently ignored on a component update.
 */
final class ResolveShopFromHost
{
    // === CONSTANTS ===
    public const NOT_FOUND_VIEW = 'tenancy.no-such-store';

    public function __construct(private readonly ShopHandleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        RequestedShop::clear();

        if (! ShopHosts::enabled()) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $handle = ShopHosts::handleFromHost($host);

        if ($handle === null) {
            // The root (or Railway / loopback) → platform mode. A nested label
            // under the root (`a.b.app.lets.co.il`) is nobody's store.
            if (ShopHosts::isUnderRoot($host) && ! ShopHosts::isRootHost($host)) {
                $this->notFound($host);
            }

            return $next($request);
        }

        $decision = $this->resolver->decide($handle);

        if ($decision[0] === ShopHandleResolver::KIND_ALIAS) {
            $target = ShopHosts::shopOrigin((string) $decision[1]).$request->getRequestUri();

            throw new HttpResponseException(redirect()->to($target, Response::HTTP_MOVED_PERMANENTLY));
        }

        $shop = $decision[0] === ShopHandleResolver::KIND_SHOP
            ? $this->resolver->shop($handle)
            : null;

        if ($shop === null) {
            $this->notFound($host);
        }

        RequestedShop::set($shop);
        app()->terminating(static fn () => RequestedShop::clear());

        return $next($request);
    }

    private function notFound(string $host): never
    {
        Log::info('tenancy.unknown_shop_host', ['host' => $host]);

        throw new HttpResponseException(response()->view(self::NOT_FOUND_VIEW, [
            'host' => $host,
            'rootUrl' => ShopHosts::rootAdminUrl(),
        ], Response::HTTP_NOT_FOUND));
    }
}
