<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

use App\Models\InstallmentPaymentMethod;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-shop PayPlus REST gateway. Ported + multi-tenant-refactored from the
 * reference engine's PayPlusInstallmentGateway. The single most important
 * change vs. the reference: credentials are CONSTRUCTOR STATE, never read from
 * config('payplus_installments.payplus.*') at call time. The factory decrypts
 * the shop's bag ONCE and hands it here; an instance is never reused across
 * shops. Auth lives in headers; payloads are never logged.
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Services/PayPlus/PayPlusInstallmentGateway.php
 */
final class PayPlusGateway implements PayPlusGatewayInterface
{
    // === CONSTANTS ===
    private const PATH_CHARGE = '/Transactions/Charge';
    private const PATH_REFUND = '/Transactions/Refund';
    private const PATH_GENERATE_LINK = '/PaymentPages/generateLink';
    private const PATH_TOKEN_LIST = '/Token/List';

    private const HEADER_API_KEY = 'api-key';
    private const HEADER_SECRET_KEY = 'secret-key';
    private const HEADER_IDEMPOTENCY = 'Idempotency-Key';

    /**
     * @param array $credentials Decrypted per-shop bag from Shop::payplusConfig():
     *   api_key, secret_key, terminal_uid, cashier_uid, payment_page_uid, base_url, webhook_secret.
     */
    public function __construct(
        private readonly array $credentials,
        private readonly string $apiPrefix,
        private readonly int $timeout,
        private readonly string $currency,
    ) {}

    public function chargeWithReference(
        InstallmentPaymentMethod $method,
        float $amount,
        string $idempotencyKey,
        array $meta = [],
    ): GatewayResult {
        // (c) Resolve the token via the reference engine's fallback chain:
        // the card-token uid, then a plain token reference, then the decrypted
        // raw token blob. Match the reference exactly — do not post token => null.
        $token = $method->payplus_card_token_uid
            ?: $method->payplus_token_reference
            ?: ($method->encrypted_payplus_token !== null ? $method->rawToken : null);

        $customerUid = $method->payplus_customer_uid;

        // (c) Fail closed before any HTTP when there is nothing to charge against
        // — never let PayPlus reject a token => null on our behalf.
        if (($token === null || $token === '') && ($customerUid === null || $customerUid === '')) {
            return GatewayResult::transportFailure(
                'no_reference',
                'No PayPlus token or customer reference available for this payment method.',
            );
        }

        // POST /Transactions/Charge with use_token=true — merchant-initiated
        // charge against a saved vault token.
        // (a) credit_terms => 1 (single payment).
        // (b) array_filter drops null/'' fields so we never send empty
        //     cashier_uid / customer_uid / token.
        $payload = array_filter([
            'terminal_uid' => $this->cred('terminal_uid'),
            'cashier_uid' => $this->cred('cashier_uid'),
            'amount' => round($amount, 2),
            'currency_code' => $meta['currency'] ?? $this->currency,
            'credit_terms' => 1,
            'use_token' => true,
            'token' => $token,
            'customer_uid' => $customerUid,
            // more_info is the correlation marker reconciliation uses to find a
            // stuck charge by idempotency key (scar tissue: recoverStuckRecurringPayment).
            'more_info' => $idempotencyKey,
        ], static fn ($v): bool => $v !== null && $v !== '');

        return $this->post(self::PATH_CHARGE, $payload, $idempotencyKey);
    }

    public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
    {
        $payload = [
            'terminal_uid' => $this->cred('terminal_uid'),
            'transaction_uid' => $transactionUid,
            'amount' => round($amount, 2),
            'currency_code' => $meta['currency'] ?? $this->currency,
        ];

        // Refunds carry their own idempotency key when the caller supplies one.
        return $this->post(self::PATH_REFUND, $payload, $meta['idempotency_key'] ?? null);
    }

    public function generateLink(array $payload): GatewayResult
    {
        $payload = array_merge([
            'payment_page_uid' => $this->cred('payment_page_uid'),
            'terminal_uid' => $this->cred('terminal_uid'),
            'currency_code' => $this->currency,
        ], $payload);

        return $this->post(self::PATH_GENERATE_LINK, $payload, $payload['more_info'] ?? null);
    }

    public function lookupVaultToken(array $payload): GatewayResult
    {
        $payload = array_merge([
            'terminal_uid' => $this->cred('terminal_uid'),
        ], $payload);

        return $this->post(self::PATH_TOKEN_LIST, $payload, null);
    }

    // === Internals ===

    private function cred(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    private function endpoint(string $path): string
    {
        $base = rtrim((string) ($this->credentials['base_url'] ?? config('payplus.base_url')), '/');

        return $base.$this->apiPrefix.$path;
    }

    private function client(?string $idempotencyKey): PendingRequest
    {
        $headers = [
            self::HEADER_API_KEY => (string) $this->cred('api_key'),
            self::HEADER_SECRET_KEY => (string) $this->cred('secret_key'),
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[self::HEADER_IDEMPOTENCY] = $idempotencyKey;
        }

        return Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function post(string $path, array $payload, ?string $idempotencyKey): GatewayResult
    {
        try {
            $response = $this->client($idempotencyKey)->post($this->endpoint($path), $payload);
        } catch (Throwable $e) {
            // Never log payloads/credentials — only the safe shape (path + class).
            Log::warning('payplus.gateway.transport_error', [
                'path' => $path,
                'exception' => $e::class,
            ]);

            return GatewayResult::transportFailure('transport_error', $e->getMessage());
        }

        $body = $response->json();

        if (! is_array($body)) {
            return GatewayResult::transportFailure(
                'malformed_response',
                'PayPlus returned a non-JSON body (HTTP '.$response->status().').',
            );
        }

        return GatewayResult::fromResponse($body);
    }
}
