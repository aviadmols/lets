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

    /** The offer's add-on window closed before the accept arrived. Nothing charged. */
    public const RESULT_EXPIRED = 'expired';

    /** A bundle accept whose pick is not exactly what the bundle allows. Nothing charged. */
    public const RESULT_INVALID_SELECTION = 'invalid_selection';

    /** A charge for this key is still unsettled (at PayPlus, or awaiting reconcile). Nothing charged. */
    public const RESULT_IN_FLIGHT = 'in_progress';

    /** The offer is not live, or was never put in front of this order. Nothing charged. */
    public const RESULT_NOT_ELIGIBLE = 'not_eligible';

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

    public static function expired(string $key): self
    {
        return new self(self::RESULT_EXPIRED, $key);
    }

    public static function invalidSelection(string $key): self
    {
        return new self(self::RESULT_INVALID_SELECTION, $key);
    }

    public static function inFlight(string $key): self
    {
        return new self(self::RESULT_IN_FLIGHT, $key);
    }

    public static function notEligible(string $key): self
    {
        return new self(self::RESULT_NOT_ELIGIBLE, $key);
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
