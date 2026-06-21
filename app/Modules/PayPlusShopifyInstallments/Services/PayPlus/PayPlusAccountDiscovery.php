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

    /** Typed failure reasons surfaced to the UI (NEVER the secret/raw body). */
    public const REASON_NONE = null;
    public const REASON_AUTH = 'auth';
    public const REASON_TRANSPORT = 'transport';
    public const REASON_MALFORMED = 'malformed';

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
            $this->lastReason = self::REASON_AUTH;

            return null;
        }

        if (! $response->successful()) {
            $this->lastReason = self::REASON_TRANSPORT;

            return null;
        }

        $body = $response->json();
        $rows = $this->unwrap($body);

        if ($rows === null) {
            $this->lastReason = self::REASON_MALFORMED;
        }

        return $rows;
    }

    /**
     * Accept either a bare JSON array OR a wrapped object (e.g. {results, data:[...]}).
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

        foreach (self::WRAPPER_KEYS as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                return $body[$key];
            }
        }

        return null;
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
