<?php

namespace App\Domain\Lifecycle;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
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
 * Safety: re-reads the row under a lock and re-checks SUCCEEDED before the gateway
 * call so two concurrent refunds can't double-refund (the second sees `refunded` and
 * no-ops). The gateway call is the point of no return — the ledger transition that
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
    /**
     * @return array{ok: bool, message?: string}
     */
    public function refund(PaymentLedger $ledger, ?float $amount = null): array
    {
        $status = (string) $ledger->status;
        if ($status === LedgerStatus::REFUNDED->value) {
            return ['ok' => true, 'message' => 'already_refunded'];
        }
        if ($status !== LedgerStatus::SUCCEEDED->value) {
            return ['ok' => false, 'message' => 'not_refundable'];
        }

        $uid = (string) ($ledger->payplus_transaction_uid ?? '');
        if ($uid === '') {
            return ['ok' => false, 'message' => 'no_transaction'];
        }

        $shop = Shop::query()->findOrFail((int) $ledger->shop_id);
        $refundAmount = $amount !== null ? round($amount, 2) : round((float) $ledger->amount, 2);

        return DB::transaction(function () use ($ledger, $shop, $uid, $refundAmount): array {
            // Re-read under a lock so concurrent refunds serialise (no double-refund).
            $row = PaymentLedger::query()->lockForUpdate()->findOrFail($ledger->getKey());
            if ((string) $row->status === LedgerStatus::REFUNDED->value) {
                return ['ok' => true, 'message' => 'already_refunded'];
            }
            if ((string) $row->status !== LedgerStatus::SUCCEEDED->value) {
                return ['ok' => false, 'message' => 'not_refundable'];
            }

            $result = PayPlusGatewayFactory::for($shop)->refund($uid, $refundAmount, [
                'currency' => $row->currency ?: config('payplus.currency'),
            ]);
            if (! $result->success) {
                return ['ok' => false, 'message' => $result->errorMessage ?: 'refund_failed'];
            }

            // The money is back; record the truth. Keep the original charge response —
            // the refund details live in the Timeline event below.
            Ledger::transition($row, LedgerStatus::REFUNDED);

            $this->refundPaymentSlot($row, $result->transactionUid);

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
                context: DocumentContext::REFUND->value,
                ledgerId: (int) $row->getKey(),
                amount: $refundAmount,
            );

            return ['ok' => true];
        });
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
