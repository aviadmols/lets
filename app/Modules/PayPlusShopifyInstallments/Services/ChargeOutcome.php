<?php

namespace App\Modules\PayPlusShopifyInstallments\Services;

/**
 * The result of one ChargeOrchestrator::charge() run. A small value object the
 * job + admin retry surfaces can branch on without re-querying the ledger.
 */
final class ChargeOutcome
{
    // === CONSTANTS ===
    public const RESULT_SUCCEEDED = 'succeeded';
    public const RESULT_FAILED = 'failed';
    public const RESULT_SKIPPED = 'skipped';

    private function __construct(
        public readonly string $result,
        public readonly string $idempotencyKey,
        public readonly ?string $reason = null,
        public readonly ?string $transactionUid = null,
        public readonly ?string $errorCode = null,
        public readonly bool $willRetry = false,
        public readonly bool $isFinal = false,
    ) {}

    public static function succeeded(string $key, ?string $uid, bool $isFinal): self
    {
        return new self(self::RESULT_SUCCEEDED, $key, transactionUid: $uid, isFinal: $isFinal);
    }

    public static function failed(string $key, ?string $errorCode, bool $willRetry): self
    {
        return new self(self::RESULT_FAILED, $key, errorCode: $errorCode, willRetry: $willRetry);
    }

    public static function skipped(string $reason, string $key): self
    {
        return new self(self::RESULT_SKIPPED, $key, reason: $reason);
    }

    public function isSucceeded(): bool
    {
        return $this->result === self::RESULT_SUCCEEDED;
    }
}
