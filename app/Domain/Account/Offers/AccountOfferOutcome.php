<?php

namespace App\Domain\Account\Offers;

use App\Models\InstallmentPlan;

/**
 * What happened when a shopper clicked an offer.
 *
 * Six answers, deliberately distinct, because the account page says a different
 * sentence for each and a shopper who is told the wrong one will either call
 * support or assume they were charged:
 *
 *   OK            — the new subscription exists (and, if immediate, is paid for).
 *   UNAVAILABLE   — the offer cannot be sold right now (withdrawn, out of its
 *                   window, or the shop's charging switch is off). Nothing moved.
 *   CHARGE_FAILED — the card said no. The old subscription is untouched and the
 *                   plan the attempt created has already been cancelled.
 *   NOT_ELIGIBLE  — this shopper no longer qualifies (they took it already, or
 *                   the subscription it was offered from has ended).
 *   CHANGED       — the price or the offer moved under the click, or another
 *                   click is in flight. Nothing was charged; reload and re-read.
 *   INVALID       — no such offer for this shop. Answered as a 404, like every
 *                   other miss on this surface: a distinct error would confirm
 *                   the offer exists.
 */
final class AccountOfferOutcome
{
    // === CONSTANTS ===
    public const RESULT_OK = 'ok';

    public const RESULT_UNAVAILABLE = 'unavailable';

    public const RESULT_CHARGE_FAILED = 'charge_failed';

    public const RESULT_NOT_ELIGIBLE = 'not_eligible';

    public const RESULT_CHANGED = 'changed';

    public const RESULT_INVALID = 'invalid';

    /** Every result, in the order the copy file lists them. */
    public const RESULTS = [
        self::RESULT_OK,
        self::RESULT_UNAVAILABLE,
        self::RESULT_CHARGE_FAILED,
        self::RESULT_NOT_ELIGIBLE,
        self::RESULT_CHANGED,
        self::RESULT_INVALID,
    ];

    private function __construct(
        public readonly string $result,
        public readonly ?InstallmentPlan $plan = null,
    ) {}

    public static function ok(InstallmentPlan $plan): self
    {
        return new self(self::RESULT_OK, $plan);
    }

    public static function unavailable(): self
    {
        return new self(self::RESULT_UNAVAILABLE);
    }

    public static function chargeFailed(): self
    {
        return new self(self::RESULT_CHARGE_FAILED);
    }

    public static function notEligible(): self
    {
        return new self(self::RESULT_NOT_ELIGIBLE);
    }

    public static function changed(): self
    {
        return new self(self::RESULT_CHANGED);
    }

    public static function invalid(): self
    {
        return new self(self::RESULT_INVALID);
    }

    public function isOk(): bool
    {
        return $this->result === self::RESULT_OK;
    }
}
