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
 *   - bodies are capped (a 2GB "page" is an attack on the worker, not a page);
 *   - only the web ports (80/443) — "http://public-host:22" is a port scan;
 *   - the connection is PINNED to the address the walls judged (CURLOPT_RESOLVE),
 *     so a name that answers public to the check and 169.254.169.254 to the
 *     connect (DNS rebinding, TTL 0) cannot slip between the two lookups.
 *
 * The same walls guard every OTHER merchant-steered outbound host — the
 * WooCommerce store (WooStoreEndpoint) and the merchant's own SMTP relay
 * (MailTransport) — through vet() / refusalForHost(), so there is one
 * definition of "an address we may connect to", not three.
 *
 * Never throws: an unfetchable site is a typed reason on the profile row.
 *
 * Not final on purpose: resolve() is protected so tests answer DNS themselves.
 */
class SafeSiteFetcher
{
    // === CONSTANTS ===
    public const MAX_REDIRECTS = 3;

    /** The only ports a web fetch may reach. */
    public const WEB_PORTS = [80, 443];

    /** The ports a merchant SMTP relay may use (plain, SMTPS, submission, the common alt). */
    public const SMTP_PORTS = [25, 465, 587, 2525];

    /** Default port per scheme, when the URL names none. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

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
            $vetted = $this->vet($current);
            if ($vetted['reason'] !== null) {
                return ['ok' => false, 'reason' => $vetted['reason'], 'body' => '', 'final_url' => $current];
            }

            try {
                $response = Http::withOptions(['allow_redirects' => false] + $vetted['options'])
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
        return $this->vet($url)['reason'];
    }

    /**
     * Judge a URL through every wall ONCE and, when it passes, hand back the
     * Guzzle options that pin the connection to the address that was judged.
     *
     * A caller that sends its own request (the WooCommerce client, the plugin
     * notifier) merges `options` into it — without them the HTTP library would
     * resolve the name a second time, and the second answer is the one an
     * attacker controls.
     *
     * @return array{reason: ?string, options: array<string, mixed>}
     */
    public function vet(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return ['reason' => self::REASON_INVALID_URL, 'options' => []];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));

        if (! array_key_exists($scheme, self::DEFAULT_PORTS) || $host === '') {
            return ['reason' => self::REASON_INVALID_URL, 'options' => []];
        }

        // A URL carrying a userinfo section ("a@b") is a classic parser-confusion
        // smuggle; no merchant site needs one.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['reason' => self::REASON_BLOCKED_HOST, 'options' => []];
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : self::DEFAULT_PORTS[$scheme];
        $judged = $this->judgeHost($host, $port, self::WEB_PORTS);

        if ($judged['reason'] !== null) {
            return ['reason' => $judged['reason'], 'options' => []];
        }

        return ['reason' => null, 'options' => $this->pinOptions($host, $port, $judged['address'])];
    }

    /**
     * The same walls for a bare host + port that is not a URL — a merchant's
     * SMTP relay. Null when the host may be connected to.
     *
     * @param  list<int>  $ports  the ports this kind of connection may use
     */
    public function refusalForHost(string $host, int $port, array $ports): ?string
    {
        $host = strtolower(trim(trim($host), '.'));
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->judgeHost($literal, $port, $ports)['reason'];
        }

        // A bare host name, nothing else: a scheme, path, userinfo or port
        // smuggled into the host field is a different destination than the one shown.
        if ($host === '' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return self::REASON_INVALID_URL;
        }

        return $this->judgeHost($host, $port, $ports)['reason'];
    }

    // === Internals ===

    /**
     * The address walls, shared by every caller.
     *
     * @param  list<int>  $ports
     * @return array{reason: ?string, address: ?string} the first judged address, for pinning
     */
    private function judgeHost(string $host, int $port, array $ports): array
    {
        if (! in_array($port, $ports, true)) {
            return ['reason' => self::REASON_BLOCKED_HOST, 'address' => null];
        }

        $literal = trim($host, '[]');

        // A literal address is judged directly…
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($literal)
                ? ['reason' => null, 'address' => null]
                : ['reason' => self::REASON_BLOCKED_HOST, 'address' => null];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return ['reason' => self::REASON_BLOCKED_HOST, 'address' => null];
        }

        // …a name is resolved, and EVERY answer must be public: one private
        // A record among public ones is still a door inward.
        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return ['reason' => self::REASON_UNREACHABLE, 'address' => null];
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                return ['reason' => self::REASON_BLOCKED_HOST, 'address' => null];
            }
        }

        return ['reason' => null, 'address' => $addresses[0]];
    }

    /**
     * Guzzle options that make cURL connect to $address for $host:$port instead
     * of resolving the name again. TLS still verifies against the NAME (SNI and
     * the certificate check use the URL's host), so pinning changes where we
     * connect, never who we trust. A literal-IP URL needs no pin.
     *
     * @return array<string, mixed>
     */
    private function pinOptions(string $host, int $port, ?string $address): array
    {
        if ($address === null || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $target = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']'
            : $address;

        return ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$target]]];
    }

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

        // v6 prefixes that CARRY a v4 address inside them — NAT64 (64:ff9b::/96)
        // and 6to4 (2002::/16) — can smuggle a private v4 past the filter above.
        $packed = @inet_pton($ip);
        if (is_string($packed) && strlen($packed) === 16) {
            $prefix = bin2hex(substr($packed, 0, 4));
            if (str_starts_with($prefix, '0064ff9b') || str_starts_with($prefix, '2002')) {
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
