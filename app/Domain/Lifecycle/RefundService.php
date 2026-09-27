<?php

namespace App\Domain\Lifecycle;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Events\LedgerRowRefunded;
use App\Models\InstallmentPayment;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refund a SUCCEEDED ledger row through PayPlus (money OUT). The ledger is the money
 * truth: this refunds via the per-shop gateway, then transitions that exact row
 * succeeded → refunded (guarded machine) + the linked payment slot + a KIND_REFUNDED
 * Timeline event.
 *
 * Safety: BEFORE the gateway call the row is locked, every check (status, what is
 * left to refund) runs under that lock, and the amount is claimed on the row
 * (`refunding_amount`) in the same transaction — so a concurrent refund of the same
 * charge is refused as `refund_in_flight` instead of passing the same check and
 * reaching PayPlus beside it. A declined or thrown call releases the claim; a
 * success settles it into `refunded_amount`. The gateway call is the point of no return — the ledger transition that
 * follows is a single legal UPDATE that won't roll it back, and the payment-slot
 * transition is best-effort (a slot hiccup never undoes the recorded refund).
 *
 * The refund CREDIT DOCUMENT is dispatched (queued, after commit) to the invoicing
 * module once the ledger says `refunded` — see App\Domain\Invoicing\DocumentIssuer.
 * It is a no-op for a merchant who has not connected an invoicing provider.
 *
 * @phpstan-type RefundResult array{ok: bool, message?: string}
 */
final class RefundService
{
    // === CONSTANTS ===
    /** Rounding slack when deciding "has the whole sale gone back?". */
    private const EPSILON = 0.005;

    /**
     * How long a refund claim (refunding_amount) counts as IN FLIGHT. Longer
     * than the gateway's timeout by a wide margin; older is a dead request.
     */
    private const IN_FLIGHT_MINUTES = 10;

    /**
     * @param  DocumentContext|null  $context  which paperwork this money is for.
     *                                         REFUND by default; a CANCELLATION
     *                                         credits the same money under the
     *                                         merchant's cancellation document
     *                                         type, which their books may map
     *                                         differently. Anything that is not
     *                                         a credit context is refused rather
     *                                         than filed as a sale.
     * @param  int|null  $refundRequestId  the merchant decision this belongs to,
     *                                     written onto the ledger row and onto the
     *                                     credit note so the three can be walked
     *                                     between later.
     * @return array{ok: bool, message?: string, amount: float, ledger_id: int, refund_uid?: ?string}
     */
    public function refund(
        PaymentLedger $ledger,
        ?float $amount = null,
        ?DocumentContext $context = null,
        ?int $refundRequestId = null,
    ): array {
        $ledgerId = (int) $ledger->getKey();
        $context = $this->creditContext($context);

        // === RESERVE — lock, check, and claim the amount in ONE transaction ===
        // The over-limit check used to read the row unlocked and lock only AFTER
        // the gateway call, so two refunds racing on one charge could both pass
        // "does this fit in what is left?" and both reach PayPlus. Now the check
        // runs under the row lock and the amount is written onto the row as
        // `refunding_amount` before the lock is released; a second refund
        // meanwhile finds it and is refused, instead of being sent beside it.
        $reserved = DB::transaction(fn (): array => $this->reserve($ledgerId, $amount));

        if (! $reserved['ok']) {
            return ['ok' => $reserved['message'] === 'already_refunded', 'message' => $reserved['message'], 'amount' => 0.0, 'ledger_id' => $ledgerId];
        }

        $uid = $reserved['uid'];
        $charged = $reserved['charged'];
        $alreadyRefunded = $reserved['already_refunded'];
        $refundAmount = $reserved['amount'];

        $shop = Shop::query()->findOrFail((int) $ledger->shop_id);

        // MONEY OUT NEEDS A KEY. The gateway only sends an Idempotency-Key header
        // when the caller supplies one, and refunds supplied none: a worker that
        // died after PayPlus refunded but before the row was written left the
        // admin looking at a "failed" refund, and the second click was a second
        // real refund with nothing to collapse it against.
        $refundKey = IdempotencyKey::refund(
            (int) $ledger->shop_id,
            (int) $ledger->getKey(),
            $alreadyRefunded,
            $refundAmount,
        );

        // THE CALL IS OUTSIDE EVERY TRANSACTION — it moves real money, and a
        // rollback cannot un-move it. The lock below re-reads the row and
        // re-checks the arithmetic before anything is written.
        try {
            $result = PayPlusGatewayFactory::for($shop)->refund($uid, $refundAmount, [
                'currency' => $ledger->currency ?: config('payplus.currency'),
                'idempotency_key' => $refundKey,
            ]);
        } catch (\Throwable $e) {
            // Nothing recorded, as before — and the claim is let go so the
            // merchant can try again (same amount → same key → PayPlus collapses).
            $this->releaseReservation($ledgerId);

            throw $e;
        }

        if (! $result->success) {
            $this->releaseReservation($ledgerId);

            return [
                'ok' => false,
                'message' => $result->errorMessage ?: 'refund_failed',
                'amount' => 0.0,
                'ledger_id' => $ledgerId,
            ];
        }

        return DB::transaction(function () use ($ledger, $uid, $refundAmount, $charged, $alreadyRefunded, $result, $context, $refundRequestId, $refundKey): array {
            // Re-read under a lock so concurrent refunds serialise (no double-refund).
            $row = PaymentLedger::query()->lockForUpdate()->findOrFail($ledger->getKey());

            $refundedTotal = round((float) ($row->refunded_amount ?? 0) + $refundAmount, 2);
            $row->forceFill(array_filter([
                'refunded_amount' => $refundedTotal,
                // Which decision reversed this charge. Null on the direct path
                // (an admin clicking a single row), which is why it is filtered.
                'refund_request_id' => $refundRequestId,
            ], static fn ($v): bool => $v !== null) + [
                // The claim is settled into refunded_amount above.
                'refunding_amount' => 0,
                'refunding_started_at' => null,
            ])->save();

            // A PARTIAL refund leaves the row `succeeded`: the sale still stands
            // for the part that was not given back, and the next partial refund
            // must be allowed rather than met with "already refunded". Only when
            // the whole sale has gone back does the row become `refunded`.
            $fullyRefunded = $refundedTotal >= round($charged - self::EPSILON, 2);

            if ($fullyRefunded) {
                Ledger::transition($row, LedgerStatus::REFUNDED);
                $this->refundPaymentSlot($row, $result->transactionUid);
            }

            Timeline::record(
                kind: Timeline::KIND_REFUNDED,
                details: [
                    'original_transaction_uid' => $uid,
                    'refund_transaction_uid' => $result->transactionUid,
                    'amount' => $refundAmount,
                    'currency' => $row->currency,
                ],
                planId: $row->plan_id,
                paymentId: $row->getAttribute('payment_id'),
                shopId: (int) $row->shop_id,
            );

            // The credit note. QUEUED + afterCommit — we are inside the refund
            // transaction, so no HTTP here, and an invoicing outage must never make a
            // refund that already left the merchant's account look like it failed.
            // The amount is passed explicitly: a PARTIAL refund credits less than the
            // original sale, and the credit note must say so.
            IssueDocumentJob::queueAfterCommit(
                shopId: (int) $row->shop_id,
                context: $context->value,
                ledgerId: (int) $row->getKey(),
                amount: $refundAmount,
                alreadyRefunded: $alreadyRefunded,
                refundRequestId: $refundRequestId,
            );

            // Observers (loyalty clawback) — after commit, never inside the money.
            LedgerRowRefunded::afterCommit((int) $row->shop_id, $row, $refundAmount, $refundKey);

            return [
                'ok' => true,
                'amount' => $refundAmount,
                'ledger_id' => (int) $row->getKey(),
                'refund_uid' => $result->transactionUid,
            ];
        });
    }

    /**
     * Under the row lock (the caller's transaction): every refundability check,
     * then the claim on the amount.
     *
     * @return array{ok: bool, message: ?string, uid: string, charged: float, already_refunded: float, amount: float}
     */
    private function reserve(int $ledgerId, ?float $amount): array
    {
        $refuse = static fn (string $message): array => [
            'ok' => false, 'message' => $message, 'uid' => '', 'charged' => 0.0, 'already_refunded' => 0.0, 'amount' => 0.0,
        ];

        $row = PaymentLedger::query()->lockForUpdate()->find($ledgerId);
        if ($row === null) {
            return $refuse('not_refundable');
        }

        $status = (string) $row->status;
        if ($status === LedgerStatus::REFUNDED->value) {
            return $refuse('already_refunded');
        }
        if ($status !== LedgerStatus::SUCCEEDED->value) {
            return $refuse('not_refundable');
        }

        $uid = (string) ($row->payplus_transaction_uid ?? '');
        if ($uid === '') {
            return $refuse('no_transaction');
        }

        // Another refund of this charge is at PayPlus right now. A claim older
        // than the window belongs to a request that died mid-call: it is taken
        // over, because the refund key is derived from (already refunded,
        // amount) and a same-amount retry collapses onto the first at PayPlus.
        $inFlight = round((float) ($row->refunding_amount ?? 0), 2);
        $startedAt = $row->refunding_started_at;
        if ($inFlight > 0 && $startedAt !== null && $startedAt->gt(now()->subMinutes(self::IN_FLIGHT_MINUTES))) {
            return $refuse('refund_in_flight');
        }
        if ($inFlight > 0) {
            Log::warning('refund.stale_reservation_taken_over', [
                'ledger_id' => $ledgerId,
                'stale_amount' => $inFlight,
                'started_at' => $startedAt?->toIso8601String(),
            ]);
        }

        $alreadyRefunded = round((float) ($row->refunded_amount ?? 0), 2);
        $charged = round((float) $row->amount, 2);
        $remaining = round($charged - $alreadyRefunded, 2);

        $refundAmount = $amount !== null ? round($amount, 2) : $remaining;

        if ($refundAmount <= 0) {
            return $refuse('nothing_to_refund');
        }
        if ($refundAmount > $remaining) {
            // Never hand back more than came in — the sum of the credit notes
            // must equal the sale, or the books stop balancing.
            return $refuse('exceeds_remaining');
        }

        $row->forceFill(['refunding_amount' => $refundAmount, 'refunding_started_at' => now()])->save();

        return [
            'ok' => true,
            'message' => null,
            'uid' => $uid,
            'charged' => $charged,
            'already_refunded' => $alreadyRefunded,
            'amount' => $refundAmount,
        ];
    }

    /** Let go of a claim whose refund did not happen. */
    private function releaseReservation(int $ledgerId): void
    {
        try {
            DB::transaction(static function () use ($ledgerId): void {
                PaymentLedger::query()->lockForUpdate()->find($ledgerId)
                    ?->forceFill(['refunding_amount' => 0, 'refunding_started_at' => null])
                    ->save();
            });
        } catch (\Throwable $e) {
            // A stuck claim only blocks refunds of this row until it goes stale.
            Log::warning('refund.release_reservation_failed', ['ledger_id' => $ledgerId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The paperwork context for money going out. Only a CREDIT context is legal
     * here — filing a refund as a sale would report income the merchant never
     * received, so an unexpected value falls back to REFUND rather than being
     * trusted.
     */
    private function creditContext(?DocumentContext $context): DocumentContext
    {
        return $context !== null && $context->isCredit() ? $context : DocumentContext::REFUND;
    }

    /** Best-effort: transition the linked payment slot to refunded (never fails the refund). */
    private function refundPaymentSlot(PaymentLedger $row, ?string $refundUid): void
    {
        $paymentId = $row->getAttribute('payment_id');
        if ($paymentId === null) {
            return;
        }

        try {
            $payment = InstallmentPayment::query()->find($paymentId);
            if ($payment !== null && $payment->status === PaymentStatus::SUCCEEDED) {
                $payment->transitionTo(PaymentStatus::REFUNDED, ['refund_transaction_uid' => $refundUid]);
            }
        } catch (\Throwable $e) {
            Log::warning('refund.slot_transition_failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
