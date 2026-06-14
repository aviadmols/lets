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
 */
enum PlanStatus: string
{
    case DRAFT = 'draft';
    case AWAITING_FIRST_PAYMENT = 'awaiting_first_payment';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

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
            self::AWAITING_FIRST_PAYMENT->value => [self::ACTIVE, self::CANCELLED],
            self::ACTIVE->value => [self::PAUSED, self::FAILED, self::COMPLETED, self::CANCELLED],
            self::PAUSED->value => [self::ACTIVE, self::CANCELLED],
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
