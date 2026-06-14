<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * The payment-SLOT lifecycle machine for a single InstallmentPayment (one deposit,
 * one installment sequence, or one recurring cycle within a plan).
 *
 * This is its OWN state machine — it is NOT the canonical PaymentLedgerStatus
 * (LedgerStatus) money-truth machine and does NOT mirror it. The payment_ledger
 * is the append-only money truth; this slot tracks the disposition of a retryable
 * charge attempt across multiple gateway calls, so it permits transitions the
 * ledger machine does not (notably failed → succeeded on a same-slot retry).
 *
 * Coherent slot lifecycle (every transition the orchestrator performs is here):
 *   pending → succeeded | failed | retry_scheduled
 *   failed → retry_scheduled | succeeded   (a retry of the same slot can recover)
 *   retry_scheduled → succeeded | failed
 *   succeeded → refunded
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case RETRY_SCHEDULED = 'retry_scheduled';
    case REFUNDED = 'refunded';

    /** @return array<string, list<self>> */
    public static function allowed(): array
    {
        return [
            self::PENDING->value => [self::SUCCEEDED, self::FAILED, self::RETRY_SCHEDULED],
            self::FAILED->value => [self::RETRY_SCHEDULED, self::SUCCEEDED],
            self::RETRY_SCHEDULED->value => [self::SUCCEEDED, self::FAILED],
            self::SUCCEEDED->value => [self::REFUNDED],
            self::REFUNDED->value => [],
        ];
    }
}
