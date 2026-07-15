<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only PayPlus payment-PAGE status lookup — the "pull" half of verify-on-return (W16).
 *
 * When PayPlus does NOT push refURL_callback (which is why gateway orders were stuck "pending"),
 * the plugin asks LETS to CONFIRM the payment on the thank-you page. We query PayPlus for the
 * page request's transaction result by its page_request_uid, and report whether it was approved
 * — plus the raw body, from which WooGatewayFinalizer extracts the reusable token.
 *
 * Makes its OWN authenticated HTTP call (like PayPlusAccountDiscovery), so it does not touch the
 * PayPlusGatewayInterface (whose 14 test fakes would otherwise all need a new method). Uses the
 * shop's decrypted creds. Fail-closed + fail-soft: any transport/parse problem returns
 * approved=false; never throws.
 */
final class PayPlusPageStatus
{
    // === CONSTANTS ===
    /** PayPlus IPN: transaction data for a payment-page request. */
    private const PATH_IPN = '/PaymentPages/ipn';

    /** Status codes PayPlus uses for an approved transaction (mirror the gateway callback). */
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];

    /** Where an approved transaction's status_code can appear in the IPN body (searched in order). */
    private const STATUS_PATHS = [
        'data.transaction.status_code',
        'data.status_code',
        'transaction.status_code',
        'status_code',
        'data.transactions.0.status_code',
        'results.status',
        'data.status',
        'status',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly string $apiPrefix,
        private readonly int $timeout,
    ) {}

    /** Build for a shop from its decrypted PayPlus creds + config (same source as the gateway). */
    public static function for(Shop $shop): self
    {
        $cfg = $shop->payplusConfig();

        return new self(
            apiKey: (string) ($cfg['api_key'] ?? ''),
            secretKey: (string) ($cfg['secret_key'] ?? ''),
            baseUrl: (string) ($cfg['base_url'] ?: config('payplus.base_url')),
            apiPrefix: (string) config('payplus.api_prefix', '/api/v1.0'),
            timeout: (int) config('payplus.timeout', 30),
        );
    }

    /**
     * Look up a payment page request's outcome.
     *
     * @return array{ok: bool, approved: bool, status_code: string, body: array<string, mixed>}
     *   ok = the call itself succeeded (2xx); approved = the transaction is a success; body =
     *   the raw PayPlus body (the token source for the finalizer).
     */
    public function status(string $pageRequestUid): array
    {
        $miss = ['ok' => false, 'approved' => false, 'status_code' => '', 'body' => []];

        if ($pageRequestUid === '' || $this->apiKey === '' || $this->secretKey === '') {
            return $miss;
        }

        try {
            $response = Http::withHeaders([
                PayPlusGateway::HEADER_API_KEY => $this->apiKey,
                PayPlusGateway::HEADER_SECRET_KEY => $this->secretKey,
            ])
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->endpoint(self::PATH_IPN), ['page_request_uid' => $pageRequestUid]);
        } catch (Throwable $e) {
            Log::warning('payplus.page_status.transport_error', ['exception' => $e::class]);

            return $miss;
        }

        if (! $response->successful()) {
            Log::warning('payplus.page_status.http_error', ['status' => $response->status()]);

            return $miss;
        }

        $body = (array) $response->json();

        $statusCode = '';
        foreach (self::STATUS_PATHS as $path) {
            $value = data_get($body, $path);
            if ($value !== null && $value !== '') {
                $statusCode = strtolower((string) $value);
                break;
            }
        }

        // Help confirm the real PayPlus IPN shape against a live transaction (no secrets logged).
        // Emits a shallow key-map of the nested objects + whether a token/amount surfaced, so ONE
        // real transaction confirms STATUS_PATHS, SUCCESS_CODES, and the token path in
        // WooDepositTokenResolver::TOKEN_PATHS all at once. Adjust the constants only if this shows
        // a path we don't already read.
        Log::info('payplus.page_status.checked', [
            'status_code' => $statusCode,
            'approved' => in_array($statusCode, self::SUCCESS_CODES, true),
            'top_level_keys' => array_keys($body),
            'data_keys' => is_array($d = data_get($body, 'data')) ? array_keys($d) : null,
            'transaction_keys' => is_array($t = data_get($body, 'data.transaction')) ? array_keys($t) : null,
            'results_keys' => is_array($r = data_get($body, 'results')) ? array_keys($r) : null,
            // Did a token field surface anywhere we look? (boolean only — never the token itself.)
            'has_token' => (bool) (
                data_get($body, 'data.transaction.token_uid')
                ?? data_get($body, 'data.token_uid')
                ?? data_get($body, 'transaction.token_uid')
                ?? data_get($body, 'token_uid')
            ),
            // The captured amount, to eyeball against the order total (not a secret).
            'amount' => data_get($body, 'data.transaction.amount') ?? data_get($body, 'data.amount') ?? data_get($body, 'amount'),
        ]);

        return [
            'ok' => true,
            'approved' => in_array($statusCode, self::SUCCESS_CODES, true),
            'status_code' => $statusCode,
            'body' => $body,
        ];
    }

    /** Mirror PayPlusGateway::endpoint(): rtrim(base) . api_prefix . path. */
    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$this->apiPrefix.$path;
    }
}
