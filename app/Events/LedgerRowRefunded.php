<?php

namespace App\Events;

use App\Models\PaymentLedger;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Money we recorded went back — some or all of one ledger row.
 *
 * The mirror of LedgerRowSucceeded: fired once per recorded refund slice, AFTER
 * the refund's transaction commits, by every path that writes refunded_amount
 * (a gateway refund through RefundService, a store-side refund mirrored by the
 * RefundOrchestrator). Listeners are observers; nothing they do may fail the
 * refund that already happened.
 *
 * `refundRef` names THIS refund slice deterministically, so a listener that
 * reverses something (loyalty points) can key its own write on it and a replay
 * collapses onto one row.
 */
final class LedgerRowRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly int $shopId,
        public readonly PaymentLedger $row,
        public readonly float $amount,
        public readonly string $refundRef,
    ) {}

    /** Dispatch once the surrounding transaction commits (immediately when there is none). */
    public static function afterCommit(int $shopId, PaymentLedger $row, float $amount, string $refundRef): void
    {
        DB::afterCommit(static function () use ($shopId, $row, $amount, $refundRef): void {
            try {
                self::dispatch($shopId, $row, $amount, $refundRef);
            } catch (\Throwable $e) {
                Log::warning('ledger.refunded_event_failed', [
                    'shop_id' => $shopId,
                    'ledger_id' => $row->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
