<?php

namespace App\Domain\Tenancy;

use App\Models\Shop;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * The host map of the admin: `app.lets.co.il` is the platform ROOT (login
 * chooser, platform screens, every machine endpoint), and `<handle>.app.lets.co.il`
 * is ONE shop's admin. Everything that reads or builds such a host goes through
 * here, so the middleware, the link builders and the trusted-host list agree.
 *
 * SUBDOMAINS ARE A SWITCH (`tenancy.subdomains_enabled`, env
 * SHOP_SUBDOMAINS_ENABLED): off, every admin link is built on APP_URL exactly as
 * before and a `<handle>.` host is never resolved — so this code can deploy
 * before the wildcard DNS + certificate exist (plan phase 0) without sending a
 * single merchant to a host that does not answer yet.
 *
 * APP_URL is never touched: it feeds every machine URL (callbacks, webhooks,
 * the plugin, the toml) and stays `https://app.lets.co.il`.
 */
final class ShopHosts
{
    // === CONSTANTS ===
    public const DEFAULT_ROOT = 'app.lets.co.il';

    public const ADMIN_PATH = '/admin';

    /**
     * Route URIs that belong to the ADMIN surface and therefore stay on the host
     * they were generated on. Every other route (customer pages, signed links,
     * callbacks) is built on APP_URL even inside a shop-host request — owner
     * decision C: customer-facing pages stay on app.lets.co.il.
     */
    public const ADMIN_URI_PREFIXES = ['admin', 'livewire', 'filament'];

    public const ADMIN_ROUTE_NAME_PREFIXES = ['filament.', 'livewire.'];

    /**
     * Hosts trusted besides the root + its subdomains: Railway's own domains
     * (the health check arrives as healthcheck.railway.app, a service as
     * *.up.railway.app, private networking as *.railway.internal) and loopback.
     */
    public const PLATFORM_HOST_PATTERNS = [
        '^(.+\.)?railway\.app$',
        '^(.+\.)?railway\.internal$',
        '^localhost$',
        '^127\.0\.0\.1$',
    ];

    public static function enabled(): bool
    {
        return (bool) config('tenancy.subdomains_enabled', false);
    }

    /** `app.lets.co.il` — lowercase, no scheme, no port. */
    public static function rootHost(): string
    {
        $root = strtolower(trim((string) config('tenancy.admin_root_host', self::DEFAULT_ROOT)));
        $root = (string) preg_replace('#^https?://#', '', $root);

        return explode(':', explode('/', $root)[0])[0] ?: self::DEFAULT_ROOT;
    }

    /** `.app.lets.co.il` — what every shop host ends with (also the cookie domain). */
    public static function suffix(): string
    {
        return '.'.self::rootHost();
    }

    public static function isRootHost(string $host): bool
    {
        return strtolower($host) === self::rootHost();
    }

    /**
     * The handle a host names, or null when the host is not `<label>.<root>`.
     * The label is returned normalised but NOT validated — the resolver decides
     * whether it is a store; a nested `a.b.<root>` is null here and refused by
     * isUnderRoot() at the middleware.
     */
    public static function handleFromHost(string $host): ?string
    {
        $host = strtolower($host);
        $suffix = self::suffix();

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.') ? $label : null;
    }

    /** Is $host the root or anything beneath it? */
    public static function isUnderRoot(string $host): bool
    {
        $host = strtolower($host);

        return $host === self::rootHost() || str_ends_with($host, self::suffix());
    }

    /** `https://<handle>.app.lets.co.il/admin<path>` (scheme + port follow APP_URL). */
    public static function shopAdminUrl(string $handle, string $path = ''): string
    {
        return self::origin($handle.'.'.self::rootHost()).self::ADMIN_PATH.self::path($path);
    }

    /**
     * The platform root's admin: `https://app.lets.co.il/admin<path>`. With the
     * switch off this is APP_URL's admin, exactly the link every mail used before.
     */
    public static function rootAdminUrl(string $path = ''): string
    {
        $base = self::enabled()
            ? self::origin(self::rootHost())
            : rtrim((string) config('app.url'), '/');

        return $base.self::ADMIN_PATH.self::path($path);
    }

    /** THE builder behind Shop::adminUrl(): the shop's own host, or the root. */
    public static function adminUrlFor(Shop $shop, string $path = ''): string
    {
        $handle = (string) $shop->handle;

        return self::enabled() && ShopHandle::isValid($handle)
            ? self::shopAdminUrl($handle, $path)
            : self::rootAdminUrl($path);
    }

    /**
     * Regexes for TrustHosts — matched against the REAL Host header only
     * (X-Forwarded-Host is not a trusted proxy header). Root, its subdomains,
     * APP_URL's host, Railway, loopback, and any TRUSTED_HOSTS_EXTRA entries.
     *
     * @return list<string>
     */
    public static function trustedHostPatterns(): array
    {
        $patterns = ['^(.+\.)?'.preg_quote(self::rootHost(), '#').'$'];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $patterns[] = '^'.preg_quote(strtolower($appHost), '#').'$';
        }

        foreach ((array) config('tenancy.extra_trusted_hosts', []) as $extra) {
            $extra = strtolower(trim((string) $extra));
            if ($extra !== '') {
                $patterns[] = '^'.preg_quote($extra, '#').'$';
            }
        }

        return array_values(array_unique([...$patterns, ...self::PLATFORM_HOST_PATTERNS]));
    }

    /** Does a route belong to the admin surface (stays on a shop host)? */
    public static function isAdminRoute(?Route $route): bool
    {
        if ($route === null) {
            return true; // url('/…') with no route: leave the host alone.
        }

        $name = (string) $route->getName();
        foreach (self::ADMIN_ROUTE_NAME_PREFIXES as $prefix) {
            if ($name !== '' && str_starts_with($name, $prefix)) {
                return true;
            }
        }

        $uri = ltrim($route->uri(), '/');
        foreach (self::ADMIN_URI_PREFIXES as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** APP_URL's origin (`https://app.lets.co.il`), what non-admin routes use. */
    public static function appOrigin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** scheme://host[:port], scheme and port taken from APP_URL. */
    private static function origin(string $host): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    private static function path(string $path): string
    {
        $path = trim($path);

        return $path === '' || $path === '/' ? '' : Str::start($path, '/');
    }
}
