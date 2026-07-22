<?php

namespace App\Domain\Invoicing;

/**
 * A provider's verdict on one issue attempt. Mirrors the billing engine's
 * GatewayResult: NEVER throws across the boundary — a provider outage returns a
 * failure result, and the caller records it. An unhandled exception on the
 * document path must never be able to disturb the money path.
 *
 * `raw` is the provider's response for the audit trail; it is masked by the
 * caller (ResponseMasker) before it touches the database.
 */
final class IssuedDocumentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $documentId = null,
        public readonly ?string $documentNumber = null,
        public readonly ?string $documentUrl = null,
        public readonly ?string $documentType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}

    public static function issued(
        string $documentId,
        ?string $documentNumber,
        ?string $documentUrl,
        ?string $documentType,
        array $raw = [],
    ): self {
        return new self(
            success: true,
            documentId: $documentId,
            documentNumber: $documentNumber,
            documentUrl: $documentUrl,
            documentType: $documentType,
            raw: $raw,
        );
    }

    public static function failed(
        string $errorCode,
        string $errorMessage,
        ?string $documentType = null,
        array $raw = [],
    ): self {
        return new self(
            success: false,
            documentType: $documentType,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            raw: $raw,
        );
    }
}
