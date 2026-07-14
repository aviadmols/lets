<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only PayPlus account discovery. Given a merchant's api_key + secret_key it
 * enumerates the account's terminals and (per terminal) its payment pages so the
 * PayPlus Connection screen can auto-fill terminal_uid / payment_page_uid /
 * cashier_uid instead of asking the merchant to paste opaque UIDs.
 *
 * Authentication reuses the gateway's EXACT header names (PayPlusGateway::HEADER_*)
 * and URLs are built the SAME way the gateway builds them
 * (rtrim(base_url) . api_prefix . path) — no duplicated base URL / header logic.
 *
 * Fail-closed contract: ANY transport error, non-2xx, or auth failure returns an
 * empty array and records a typed reason on $lastReason for the UI. Secrets are
 * NEVER logged (only the path + the failure class/status).
 */
final class PayPlusAccountDiscovery
{
    // === CONSTANTS ===
    private const PATH_TERMINALS = '/MyTerminals';
    private const PATH_PAYMENT_PAGES = '/PaymentPages/list/';

    /** Defensive response-wrapper keys: PayPlus may return a bare array OR {data:[...]}. */
    private const WRAPPER_KEYS = ['data', 'results', 'terminals', 'payment_pages'];

    /** How deep to hunt for the row list inside a wrapped envelope. */
    private const MAX_UNWRAP_DEPTH = 4;

    /** Chars of the response body kept in a failure log (a PayPlus RESPONSE — no secrets of ours). */
    private const LOG_BODY_CHARS = 400;

    /** Typed failure reasons surfaced to the UI (NEVER the secret/raw body). */
    public const REASON_NONE = null;
    public const REASON_AUTH = 'auth';
    public const REASON_TRANSPORT = 'transport';
    public const REASON_MALFORMED = 'malformed';

    /** The call SUCCEEDED but PayPlus has no rows (e.g. no payment page on this terminal).
        Distinct from a failure: the merchant must CREATE one in the PayPlus dashboard. */
    public const REASON_EMPTY = 'empty';

    /** Last fail-closed reason from terminals()/paymentPages(), for the screen. */
    public ?string $lastReason = self::REASON_NONE;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly string $apiPrefix,
        private readonly int $timeout,
    ) {}

    /**
     * Build from typed (not-yet-saved) credentials, pulling operational config
     * (api_prefix, timeout) from config/payplus.php — same source as the gateway.
     */
    public static function for(string $apiKey, string $secretKey, string $baseUrl): self
    {
        return new self(
            apiKey: $apiKey,
            secretKey: $secretKey,
            baseUrl: $baseUrl !== '' ? $baseUrl : (string) config('payplus.base_url'),
            apiPrefix: (string) config('payplus.api_prefix', '/api/v1.0'),
            timeout: (int) config('payplus.timeout', 30),
        );
    }

    /**
     * Enumerate the account's terminals.
     *
     * @return list<array{uid:string,name:string,active:bool}>
     */
    public function terminals(): array
    {
        $rows = $this->get(self::PATH_TERMINALS);

        if ($rows === null) {
            return [];
        }

        $terminals = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $uid = (string) ($row['uuid'] ?? $row['terminal_uid'] ?? $row['uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $terminals[] = [
                'uid' => $uid,
                'name' => (string) ($row['name_terminal'] ?? $row['name'] ?? $uid),
                'active' => $this->boolish($row['status'] ?? true),
            ];
        }

        return $terminals;
    }

    /**
     * Enumerate a terminal's payment pages. Each page carries BOTH its own
     * payment_page_uid AND the cashier_uid we must persist for charging.
     *
     * @return list<array{uid:string,name:string,cashier_uid:string}>
     */
    public function paymentPages(string $terminalUid): array
    {
        $rows = $this->get(self::PATH_PAYMENT_PAGES, [
            'terminal_uid' => $terminalUid,
            'skip' => 0,
            'take' => 500,
        ]);

        if ($rows === null) {
            return [];
        }

        $pages = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $uid = (string) ($row['uid'] ?? $row['payment_page_uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $pages[] = [
                'uid' => $uid,
                'name' => (string) ($row['name'] ?? $uid),
                'cashier_uid' => (string) ($row['cashier_uid'] ?? ''),
            ];
        }

        return $pages;
    }

    // === Internals ===

    /**
     * Authenticated GET. Returns the unwrapped row list on 2xx, or NULL on any
     * failure (transport / non-2xx / malformed) after stamping $lastReason.
     *
     * @return list<mixed>|null
     */
    private function get(string $path, array $query = []): ?array
    {
        $this->lastReason = self::REASON_NONE;

        try {
            $response = Http::withHeaders([
                PayPlusGateway::HEADER_API_KEY => $this->apiKey,
                PayPlusGateway::HEADER_SECRET_KEY => $this->secretKey,
            ])
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->endpoint($path), $query);
        } catch (Throwable $e) {
            // Never log credentials/payloads — only the safe shape.
            Log::warning('payplus.discovery.transport_error', [
                'path' => $path,
                'exception' => $e::class,
            ]);
            $this->lastReason = self::REASON_TRANSPORT;

            return null;
        }

        if ($response->unauthorized() || $response->forbidden()) {
            // PayPlus rejected the api-key/secret-key pair.
            Log::warning('payplus.discovery.auth_failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);
            $this->lastReason = self::REASON_AUTH;

            return null;
        }

        if (! $response->successful()) {
            // 404/405/500 — e.g. a wrong verb or path. Log the STATUS + a snippet of
            // PayPlus's own response so the real cause is visible (never our secrets).
            Log::warning('payplus.discovery.http_error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $this->snippet($response->body()),
            ]);
            $this->lastReason = self::REASON_TRANSPORT;

            return null;
        }

        $body = $response->json();
        $rows = $this->unwrap($body);

        if ($rows === null) {
            // 200, but we could not find a row list. Log the ENVELOPE SHAPE (top-level
            // keys only) — this is what silently produced an empty payment-page list.
            Log::warning('payplus.discovery.malformed', [
                'path' => $path,
                'top_level_keys' => is_array($body) ? array_keys($body) : gettype($body),
                'body' => $this->snippet($response->body()),
            ]);
            $this->lastReason = self::REASON_MALFORMED;

            return null;
        }

        if ($rows === []) {
            // A genuine "no rows" answer — NOT a failure. The merchant has no payment
            // page on this terminal and must create one in the PayPlus dashboard.
            Log::info('payplus.discovery.empty', ['path' => $path]);
            $this->lastReason = self::REASON_EMPTY;
        }

        return $rows;
    }

    /**
     * Find the row list inside PayPlus's envelope. PayPlus really answers
     * {"results":{status,code},"data":{...}} and the rows can sit a level DEEPER than
     * the wrapper key (e.g. data.payment_pages[]) — the old one-level lookup returned
     * null there and silently produced an empty list. Search depth-bounded, preferring
     * the known wrapper keys, and only accept a list whose elements are ROWS (objects).
     *
     * @return list<mixed>|null
     */
    private function unwrap(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        // Bare array (numeric keys) → the list itself.
        if ($body === [] || array_is_list($body)) {
            return $body;
        }

        return $this->findRowList($body, 0);
    }

    /** @return list<mixed>|null */
    private function findRowList(array $node, int $depth): ?array
    {
        if ($depth > self::MAX_UNWRAP_DEPTH) {
            return null;
        }

        // Prefer the documented wrapper keys at this level.
        foreach (self::WRAPPER_KEYS as $key) {
            if ($this->isRowList($node[$key] ?? null)) {
                return $node[$key];
            }
        }

        // Otherwise descend: {"data": {"payment_pages": [ {...} ]}} and friends.
        foreach ($node as $child) {
            if (! is_array($child)) {
                continue;
            }

            if ($this->isRowList($child)) {
                return $child;
            }

            if (! array_is_list($child)) {
                $found = $this->findRowList($child, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** A list of ROWS (objects) — or a legitimately empty list. Not a list of scalars. */
    private function isRowList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && ($value === [] || is_array($value[0]));
    }

    /** A short, safe excerpt of a PayPlus RESPONSE body for failure logs. */
    private function snippet(string $body): string
    {
        return mb_substr(trim($body), 0, self::LOG_BODY_CHARS);
    }

    /** Mirror PayPlusGateway::endpoint(): rtrim(base) . api_prefix . path. */
    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$this->apiPrefix.$path;
    }

    /** PayPlus `status` may arrive as a bool, 1/0, or "true"/"active" string. */
    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'active', 'yes'], true);
    }
}
