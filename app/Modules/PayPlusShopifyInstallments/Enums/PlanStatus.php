<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * The plan lifecycle status. ONE enum serves both plan kinds; the ALLOWED table
 * is the union of the two canonical machines in ARCHITECTURE.md. The guarded
 * transitionTo() consults ALLOWED and rejects anything not listed.
 *
 * InstallmentPlanStatus:
 *   draft → awaiting_first_payment → active → completed
 *   draft → cancelled · awaiting_first_payment → cancelled
 *   active → paused · paused → active · active → failed · failed → active · failed → cancelled
 *
 * RecurringPlanStatus (no awaiting_first_payment, no completed):
 *   draft → active · active → paused · paused → active
 *   active → cancelled · active → failed · failed → active · failed → cancelled
 *
 * AWAITING_PAYMENT is the DUNNING state, and it is deliberately not `failed`:
 *   active → awaiting_payment (a cycle did not go through)
 *   awaiting_payment → active (money finally landed)
 *   awaiting_payment → failed (only if a human gives up on it)
 * A plan here is LIVE — still a subscriber, still scheduled, still retried. It
 * says "we are waiting for this payment", where `failed` says "we stopped
 * asking". Keeping them apart is what lets the scheduler pick this one up
 * again while the historical `failed` rows stay exactly as inert as they are.
 */
enum PlanStatus: string
{
    case DRAFT = 'draft';
    case AWAITING_FIRST_PAYMENT = 'awaiting_first_payment';
    case ACTIVE = 'active';
    case PAUSED = 'paused';

    /** Dunning: a charge did not go through and we are still trying. */
    case AWAITING_PAYMENT = 'awaiting_payment';

    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * The statuses a person still HAS a subscription in — the gates that ask
     * "does this shopper already subscribe?" read this, so a subscriber in
     * dunning is never offered a second subscription beside the one they have.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::ACTIVE, self::PAUSED, self::AWAITING_PAYMENT, self::FAILED];
    }

    /**
     * The statuses the scheduler may bill. A dunning plan belongs here — that
     * is the whole point of it: the next cycle is attempted normally.
     *
     * @return list<string>
     */
    public static function chargeable(): array
    {
        return [
            self::ACTIVE->value,
            self::AWAITING_FIRST_PAYMENT->value,
            self::AWAITING_PAYMENT->value,
        ];
    }

    /**
     * Allowed transitions, keyed by source value → list of legal targets.
     * Union of installments + recurring; the legal set for a recurring plan is a
     * subset (it simply never reaches awaiting_first_payment / completed).
     *
     * @return array<string, list<self>>
     */
    public static function allowed(): array
    {
        return [
            self::DRAFT->value => [self::AWAITING_FIRST_PAYMENT, self::ACTIVE, self::CANCELLED],
            self::AWAITING_FIRST_PAYMENT->value => [self::ACTIVE, self::AWAITING_PAYMENT, self::CANCELLED],
            self::ACTIVE->value => [self::PAUSED, self::AWAITING_PAYMENT, self::FAILED, self::COMPLETED, self::CANCELLED],
            self::PAUSED->value => [self::ACTIVE, self::CANCELLED],
            self::AWAITING_PAYMENT->value => [self::ACTIVE, self::PAUSED, self::FAILED, self::CANCELLED],
            self::FAILED->value => [self::ACTIVE, self::CANCELLED],
            self::COMPLETED->value => [],
            self::CANCELLED->value => [],
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
