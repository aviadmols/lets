<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Models\User;
use App\Support\EmbeddedSession;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The browser-side security headers, on every response:
 *
 * - X-Content-Type-Options: nosniff — a file is only ever run as the type we sent.
 * - Referrer-Policy — other sites see our origin, never the path (admin URLs
 *   carry customer and plan ids).
 * - X-Powered-By removed — no free PHP version for a scanner.
 *
 * And, on the ADMIN only (/admin, /horizon), who may put us in a frame
 * (CSP frame-ancestors) — the clickjacking wall. The admin is framed on purpose
 * in two places, and both stay open:
 *   - Shopify's admin (admin.shopify.com, a store's *.myshopify.com);
 *   - the connected store's own WordPress admin: the shop's domain (+www) and
 *     the exact wp-admin origin the plugin handed us at embed time.
 * Any other site gets a refused frame. The storefront, account and payment
 * surfaces are NOT touched here — they are framed by merchants' stores and set
 * their own policy where they need one (e.g. InstallmentModalController).
 */
final class AddSecurityHeaders
{
    // === CONSTANTS ===
    public const REFERRER_POLICY = 'strict-origin-when-cross-origin';

    /** Path prefixes that get the frame-ancestors wall. */
    public const FRAMED_ADMIN_PATHS = ['admin', 'admin/*', 'horizon', 'horizon/*'];

    /** Always allowed to frame the admin: us, and Shopify's embedded admin. */
    /** A bare hostname — nothing that could end or extend a CSP directive. */
    public const HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';

    public const BASE_FRAME_ANCESTORS = ["'self'", 'https://admin.shopify.com', 'https://*.myshopify.com'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // A DEFAULT only: a route that already chose a stricter policy keeps it
        // (the passwordless login landing sends no-referrer — its URL is a key).
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', self::REFERRER_POLICY);
        }
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->is(...self::FRAMED_ADMIN_PATHS) && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                'frame-ancestors '.implode(' ', $this->frameAncestors()),
            );
        }

        return $response;
    }

    /** @return list<string> */
    public function frameAncestors(): array
    {
        $ancestors = self::BASE_FRAME_ANCESTORS;

        $shop = $this->shop();
        $host = strtolower((string) ($shop?->woocommerce_domain ?? ''));
        // A stored value is still checked: a space or ';' must never add a directive.
        if ($shop !== null && $shop->platform === Shop::PLATFORM_WOOCOMMERCE && preg_match(self::HOST_PATTERN, $host) === 1) {
            $ancestors[] = 'https://'.$host;
            $ancestors[] = 'https://www.'.$host;
        }

        $returnOrigin = self::origin(EmbeddedSession::returnUrl());
        if ($returnOrigin !== null) {
            $ancestors[] = $returnOrigin;
        }

        return array_values(array_unique($ancestors));
    }

    private function shop(): ?Shop
    {
        try {
            $shop = Tenant::current();
            if ($shop === null) {
                $user = Auth::user();
                $shop = $user instanceof User ? $user->shop : null;
            }
        } catch (Throwable) {
            return null;
        }

        return $shop instanceof Shop ? $shop : null;
    }

    /** scheme://host[:port] of a URL, or null — never anything a CSP could be bent by. */
    private static function origin(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || preg_match(self::HOST_PATTERN, $host) !== 1) {
            return null;
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
    }
}
