<?php

namespace App\Services\PayPlus;

/**
 * What PayPlus ITSELF says about a page a callback (or a verify-on-return pull)
 * claims was paid — the verdict of PayPlusCallbackVerifier::confirm().
 *
 * Only CONFIRMED may move anything: mark an order paid, activate a plan, attach
 * a card. `body` is then the body the caller acts on — PayPlus's own record of
 * the page, never the unauthenticated callback (see the verifier's docblock for
 * the one narrow case a callback field fills a gap in it).
 */
final class PayPlusConfirmation
{
    // === CONSTANTS ===
    /** PayPlus holds an approved transaction for the page, bound to our more_info (and amount). */
    public const CONFIRMED = 'confirmed';

    /** PayPlus holds the page, bound to our more_info, and it was NOT approved. */
    public const DECLINED = 'declined';

    /** Nothing ties the claim to a genuine page of ours — act on nothing. */
    public const UNCONFIRMED = 'unconfirmed';

    /** PayPlus could not be asked (transport / non-2xx) — the caller may ask to be retried. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>  $body  the body to act on (CONFIRMED / DECLINED only)
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $body = [],
        public readonly string $reason = '',
    ) {}

    public function confirmed(): bool
    {
        return $this->outcome === self::CONFIRMED;
    }

    /** The page is genuinely OURS (paid or not) — a decline worth recording. */
    public function bound(): bool
    {
        return in_array($this->outcome, [self::CONFIRMED, self::DECLINED], true);
    }

    public function unavailable(): bool
    {
        return $this->outcome === self::UNAVAILABLE;
    }
}
