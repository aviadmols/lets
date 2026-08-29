<?php

namespace App\Domain\Brand;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a MERCHANT-NAMED website — which makes this the platform's one
 * user-steered outbound request, and therefore its one SSRF surface.
 *
 * The walls, every one of them pinned by test:
 *   - http(s) only, a real public hostname only;
 *   - the host is RESOLVED FIRST and every answer must be a public address —
 *     localhost, RFC1918, link-local (the cloud metadata service lives at
 *     169.254.169.254), CGNAT and the v6 equivalents are refused before any
 *     connection is opened;
 *   - redirects are NOT followed blindly: each hop is re-validated through the
 *     same walls, three hops maximum — "public URL that redirects to
 *     169.254.169.254" is the classic bypass;
 *   - bodies are capped (a 2GB "page" is an attack on the worker, not a page).
 *
 * Never throws: an unfetchable site is a typed reason on the profile row.
 *
 * Not final on purpose: resolve() is protected so tests answer DNS themselves.
 */
class SafeSiteFetcher
{
    // === CONSTANTS ===
    public const MAX_REDIRECTS = 3;

    public const MAX_BODY_BYTES = 2_097_152; // 2MB per page

    public const TIMEOUT_SECONDS = 12;

    /** Failure reasons (the screen translates). */
    public const REASON_INVALID_URL = 'invalid_url';

    public const REASON_BLOCKED_HOST = 'blocked_host';

    public const REASON_UNREACHABLE = 'unreachable';

    public const REASON_TOO_MANY_REDIRECTS = 'too_many_redirects';

    /**
     * Fetch one page through the walls.
     *
     * @return array{ok: bool, reason: ?string, body: string, final_url: string}
     */
    public function fetch(string $url): array
    {
        $current = trim($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $blocked = $this->refusalFor($current);
            if ($blocked !== null) {
                return ['ok' => false, 'reason' => $blocked, 'body' => '', 'final_url' => $current];
            }

            try {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['User-Agent' => 'LETS-BrandCapture/1.0'])
                    ->get($current);
            } catch (\Throwable $e) {
                Log::info('brand.fetch.unreachable', ['reason' => $e->getMessage()]);

                return ['ok' => false, 'reason' => self::REASON_UNREACHABLE, 'body' => '', 'final_url' => $current];
            }

            // A redirect is a NEW destination — back through every wall.
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    return ['ok' => false, 'reason' => self::REASON_UNREACHABLE, 'body' => '', 'final_url' => $current];
                }

                $current = $this->absolutize($location, $current);

                continue;
            }

            if (! $response->successful()) {
                return ['ok' => false, 'reason' => self::REASON_UNREACHABLE, 'body' => '', 'final_url' => $current];
            }

            return [
                'ok' => true,
                'reason' => null,
                'body' => mb_strcut($response->body(), 0, self::MAX_BODY_BYTES),
                'final_url' => $current,
            ];
        }

        return ['ok' => false, 'reason' => self::REASON_TOO_MANY_REDIRECTS, 'body' => '', 'final_url' => $current];
    }

    /**
     * Why this URL may not be fetched — or null when it may.
     *
     * Public so the capture flow can refuse a typed URL BEFORE queueing a job.
     */
    public function refusalFor(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return self::REASON_INVALID_URL;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return self::REASON_INVALID_URL;
        }

        // A URL carrying a userinfo section ("a@b") is a classic parser-confusion
        // smuggle; no merchant site needs one.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::REASON_BLOCKED_HOST;
        }

        // A literal address in the URL is judged directly…
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($host) ? null : self::REASON_BLOCKED_HOST;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return self::REASON_BLOCKED_HOST;
        }

        // …a name is resolved, and EVERY answer must be public: one private
        // A record among public ones is still a door inward.
        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return self::REASON_UNREACHABLE;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                return self::REASON_BLOCKED_HOST;
            }
        }

        return null;
    }

    // === Internals ===

    /** @return list<string> the host's A/AAAA answers */
    protected function resolve(string $host): array
    {
        if (! function_exists('dns_get_record')) {
            return [];
        }

        $addresses = [];

        try {
            foreach ((array) @dns_get_record($host, DNS_A) as $record) {
                $ip = (string) ($record['ip'] ?? '');
                if ($ip !== '') {
                    $addresses[] = $ip;
                }
            }
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
                $ip = (string) ($record['ipv6'] ?? '');
                if ($ip !== '') {
                    $addresses[] = $ip;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $addresses;
    }

    /** Public internet only — everything reserved answers false. */
    private function isPublicAddress(string $ip): bool
    {
        // PHP's own reserved/private filter covers RFC1918, loopback,
        // link-local (169.254.x — the metadata service), and the v6 blocks.
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if (! $public) {
            return false;
        }

        // CGNAT (100.64/10) is "shared", not "private", so the filter passes
        // it — but nothing a merchant owns lives there.
        if (str_starts_with($ip, '100.')) {
            $second = (int) explode('.', $ip)[1];
            if ($second >= 64 && $second <= 127) {
                return false;
            }
        }

        return true;
    }

    /** A Location header, made absolute against the page that sent it. */
    private function absolutize(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.'/'.ltrim($location, '/');
    }
}
