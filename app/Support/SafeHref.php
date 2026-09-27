<?php

namespace App\Support;

/**
 * The scheme check for a URL we did not write that is about to become an href.
 *
 * Blade's {{ }} escapes quotes and angle brackets, but a `javascript:` URL is
 * perfectly well-escaped text — it runs on click, on OUR origin. So any stored
 * URL that came from outside (a provider's document link, a merchant-typed
 * reconciliation, a store URL) passes through here first: an absolute http(s)
 * URL with a host comes back unchanged, anything else comes back null and the
 * caller draws plain text instead of a link.
 */
final class SafeHref
{
    // === CONSTANTS ===
    private const SCHEMES = ['http', 'https'];

    public static function web(?string $url): ?string
    {
        $url = trim((string) $url);

        // Control characters and whitespace inside a URL are how "java\tscript:"
        // gets past a naive prefix check; a real link has none.
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($scheme, self::SCHEMES, true) && $host !== '' ? $url : null;
    }
}
