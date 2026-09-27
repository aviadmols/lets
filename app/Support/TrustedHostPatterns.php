<?php

namespace App\Support;

/**
 * The Host headers this app answers to (Laravel TrustHosts).
 *
 * Forwarded headers are trusted from any peer (bootstrap/app.php — Railway's
 * edge is the only hop, and its addresses are not published as a stable
 * range, so the proxy list is deliberately left at '*'). What that trust must
 * not allow is a client-supplied Host / X-Forwarded-Host steering the URLs the
 * app generates at an attacker's domain. Pinning the accepted hosts closes
 * that: any other host is refused before a URL is built from it.
 *
 * The list is generous on purpose, because a host missing here is an outage:
 *   - APP_URL's host and SHOPIFY_APP_URL's host (and their subdomains);
 *   - the production domain, in case APP_URL is ever set to something else;
 *   - Railway's own domains — `*.up.railway.app` (the service domain) and
 *     `healthcheck.railway.app` (the Host Railway's HTTP healthcheck sends);
 *   - TRUSTED_HOSTS: a comma list for anything else, without a deploy of code.
 *
 * Laravel skips the check in `local` and under unit tests.
 */
final class TrustedHostPatterns
{
    // === CONSTANTS ===
    public const PRODUCTION_HOST = 'app.lets.co.il';

    /** @var list<string> */
    public const PLATFORM_HOSTS = ['up.railway.app', 'healthcheck.railway.app'];

    /** @return list<string> regex patterns, one per host, subdomains included */
    public static function patterns(): array
    {
        $hosts = array_merge(
            [self::PRODUCTION_HOST],
            self::PLATFORM_HOSTS,
            array_filter([
                parse_url((string) config('app.url'), PHP_URL_HOST),
                parse_url((string) config('shopify.app_url'), PHP_URL_HOST),
            ]),
            array_map('trim', explode(',', (string) config('app.trusted_hosts', ''))),
        );

        $patterns = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $patterns[$host] = '^(.+\.)?'.preg_quote($host).'$';
            }
        }

        return array_values($patterns);
    }
}
