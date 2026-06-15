<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Models\UpsellFlowOffer;

/**
 * The outcome of one accept-charge run. The controller branches on this to render
 * the next offer, a success view, or a graceful failure — without re-querying.
 */
final class UpsellChargeResult
{
    // === CONSTANTS ===
    public const RESULT_CHARGED = 'charged';
    public const RESULT_ALREADY = 'already_accepted'; // idempotent short-circuit
    public const RESULT_NO_CONSENT = 'no_consent';
    public const RESULT_NO_METHOD = 'no_payment_method';
    public const RESULT_FAILED = 'charge_failed';

    private function __construct(
        public readonly string $result,
        public readonly string $idempotencyKey,
        public readonly ?string $transactionUid = null,
        public readonly ?string $errorCode = null,
        public readonly ?UpsellFlowOffer $nextOffer = null,
    ) {}

    public static function charged(string $key, ?string $uid, ?UpsellFlowOffer $next): self
    {
        return new self(self::RESULT_CHARGED, $key, transactionUid: $uid, nextOffer: $next);
    }

    public static function already(string $key, ?UpsellFlowOffer $next): self
    {
        return new self(self::RESULT_ALREADY, $key, nextOffer: $next);
    }

    public static function noConsent(string $key): self
    {
        return new self(self::RESULT_NO_CONSENT, $key);
    }

    public static function noMethod(string $key): self
    {
        return new self(self::RESULT_NO_METHOD, $key);
    }

    public static function failed(string $key, ?string $errorCode): self
    {
        return new self(self::RESULT_FAILED, $key, errorCode: $errorCode);
    }

    public function isCharged(): bool
    {
        return $this->result === self::RESULT_CHARGED || $this->result === self::RESULT_ALREADY;
    }

    public function hasNextOffer(): bool
    {
        return $this->nextOffer !== null;
    }
}
