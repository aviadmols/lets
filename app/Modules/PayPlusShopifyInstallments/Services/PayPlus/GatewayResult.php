<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

/**
 * Normalized result of a PayPlus REST call. Ported from the reference engine's
 * defensive parser: PayPlus returns the transaction uid in TWO shapes
 * (data.transaction.uid nested, OR a flat data.transaction_uid), and the
 * status/code lives under `results.status` / `results.code` /
 * `results.description`. This DTO collapses both shapes to one stable surface.
 *
 * The raw response is exposed UN-masked here; the ChargeOrchestrator masks it
 * before it ever touches the ledger (raw_response_masked).
 */
final class GatewayResult
{
    // === CONSTANTS ===
    /** PayPlus signals success with results.status === 'success'. */
    public const STATUS_SUCCESS = 'success';

    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionUid = null,
        public readonly ?string $approvalNumber = null,
        public readonly ?string $documentUid = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Build from a decoded PayPlus JSON body. Handles both response shapes.
     */
    public static function fromResponse(array $body): self
    {
        $results = $body['results'] ?? [];
        $data = $body['data'] ?? [];

        $status = strtolower((string) ($results['status'] ?? ''));
        $success = $status === self::STATUS_SUCCESS;

        // transaction uid: nested OR flat. Never trust an empty string.
        $uid = $data['transaction']['uid']
            ?? $data['transaction_uid']
            ?? $data['uid']
            ?? null;
        $uid = ($uid === '' ? null : $uid);

        $approval = $data['transaction']['approval_number']
            ?? $data['approval_number']
            ?? null;

        $documentUid = $data['document_uid']
            ?? $data['invoice_uid']
            ?? ($data['document']['uid'] ?? null);
        $documentUid = ($documentUid === '' ? null : $documentUid);

        return new self(
            success: $success,
            transactionUid: $uid,
            approvalNumber: $approval !== '' ? $approval : null,
            documentUid: $documentUid,
            errorCode: $success ? null : (string) ($results['code'] ?? 'unknown'),
            errorMessage: $success ? null : (string) ($results['description'] ?? 'Unknown PayPlus error'),
            raw: $body,
        );
    }

    /** Build a transport-level failure (HTTP error, timeout, malformed body). */
    public static function transportFailure(string $code, string $message, array $raw = []): self
    {
        return new self(
            success: false,
            errorCode: $code,
            errorMessage: $message,
            raw: $raw,
        );
    }
}
