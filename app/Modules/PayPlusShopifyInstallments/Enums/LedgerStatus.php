<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * PaymentLedgerStatus (ARCHITECTURE.md). The ledger is the money truth: a row
 * opens `pending` BEFORE the PayPlus call, then transitions exactly once to a
 * terminal/retry state.
 *
 *   pending → succeeded · pending → failed
 *   succeeded → refunded
 *   failed → retry_scheduled · retry_scheduled → succeeded · retry_scheduled → failed
 */
enum LedgerStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case RETRY_SCHEDULED = 'retry_scheduled';

    /** @return array<string, list<self>> */
    public static function allowed(): array
    {
        return [
            self::PENDING->value => [self::SUCCEEDED, self::FAILED],
            self::SUCCEEDED->value => [self::REFUNDED],
            self::FAILED->value => [self::RETRY_SCHEDULED],
            self::RETRY_SCHEDULED->value => [self::SUCCEEDED, self::FAILED],
            self::REFUNDED->value => [],
        ];
    }
}
