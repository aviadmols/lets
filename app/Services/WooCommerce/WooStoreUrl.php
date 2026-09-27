<?php

namespace App\Services\WooCommerce;

/**
 * What a WooCommerce store URL is ALLOWED to look like — the one definition,
 * read wherever `base_url` leaves the database.
 *
 * `base_url` is reported by the merchant's own plugin, so it is merchant input
 * that we render into links (the deposit-return "back to store" button, the
 * admin's "#order" and "log in as customer" links, the campaign login redirect)
 * and that the worker connects to. A value that is not a plain web address —
 * `javascript:…`, a host-less `https:///x`, userinfo, a port, a query that
 * swallows the `/wp-json/...` suffix — must never reach either.
 *
 *   - canonical(): the shape every READ trusts. http(s) only, a real host name
 *     (not an IP), no userinfo, no port other than the scheme's own, no query
 *     or fragment, a plain path (a WordPress in a sub-directory is legitimate).
 *     Returns null for anything else — the link is not drawn, the call is not made.
 *   - forInstall(): the stricter shape the install handshake may STORE: https
 *     only, and the host must BE the domain the connection token was minted for.
 */
final class WooStoreUrl
{
    // === CONSTANTS ===
    private const SCHEME_HTTPS = 'https';

    /** Scheme => its own port (the only port a store URL may name). */
    private const SCHEMES = ['http' => 80, 'https' => 443];

    /** Plain path segments only (a WordPress in a sub-directory) — nothing a link or a URL parser could re-read. */
    private const PATH_PATTERN = '#^(/[A-Za-z0-9._~-]*)*$#';

    /** The canonical "scheme://host[/path]" (no trailing slash), or null when it is not a safe store URL. */
    public static function canonical(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $raw) === 1) {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! array_key_exists($scheme, self::SCHEMES) || ! self::isHostName($host)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== self::SCHEMES[$scheme]) {
            return null;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && preg_match(self::PATH_PATTERN, $path) !== 1) {
            return null;
        }

        return $scheme.'://'.$host.$path;
    }

    /**
     * The URL the install handshake may store, or null to refuse it: canonical,
     * https, and hosted on $expectedDomain (a leading "www." is the same store).
     */
    public static function forInstall(?string $raw, string $expectedDomain): ?string
    {
        $url = self::canonical($raw);
        $expectedDomain = strtolower(trim($expectedDomain));

        if ($url === null || $expectedDomain === '' || ! str_starts_with($url, self::SCHEME_HTTPS.'://')) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return (string) preg_replace('#^www\.#', '', $host) === $expectedDomain ? $url : null;
    }

    /** A DNS host name with at least one dot — never an IP literal, never "localhost". */
    private static function isHostName(string $host): bool
    {
        if ($host === '' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
