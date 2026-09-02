<?php

namespace App\Domain\Billing;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Close out a charge whose outcome nobody ever learned.
 *
 * A `pending` ledger row older than the in-flight window is the one thing the
 * charge pipeline refuses to decide for itself: we asked PayPlus for money, the
 * worker died, and the card may or may not have been charged. Guessing either
 * way in code is how you get a double charge or a lost one, so the pipeline
 * stops and marks it — and a HUMAN, who has looked at the PayPlus dashboard,
 * says which it was. That is what this service is for.
 *
 * It is deliberately NOT a PayPlus lookup. Reconciling automatically would need
 * a transaction-search endpoint answering on our `more_info` marker, and a guess
 * about that API's semantics is exactly the kind of assumption that must not sit
 * under the money. The marker is already sent on every charge, so an automatic
 * resolver can be added later on top of this; the manual answer is what makes
 * the subscription unstuck TODAY.
 *
 * Both answers are terminal and both are recorded with the person who gave them:
 *
 *   - TOOK: the money moved. The row becomes `succeeded`, the slot with it, and
 *     the plan's total advances — the same bookkeeping the pipeline would have
 *     done, minus the documents and store order, which the merchant is told to
 *     check because we cannot know whether they were already written.
 *   - DID NOT: nothing moved. The row becomes `failed`, which releases the debt
 *     back to the ordinary retry ladder and lets the next scheduled attempt run.
 */
final class StuckChargeResolver
{
    // === CONSTANTS ===
    /** The answers a human can give. */
    public const OUTCOME_TOOK = 'took';

    public const OUTCOME_DID_NOT = 'did_not';

    /** Where the flag and its resolution live on the row. */
    public const FLAG = 'needs_reconcile';

    /** Every unresolved stuck row for this shop, newest first. */
    public static function unresolved(int $shopId): Collection
    {
        return PaymentLedger::query()
            ->where('shop_id', $shopId)
            ->where('status', LedgerStatus::PENDING->value)
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (PaymentLedger $row): bool => (bool) (($row->raw_response_masked ?? [])[self::FLAG] ?? false))
            ->values();
    }

    /** Is this row waiting on a person? */
    public static function isStuck(PaymentLedger $row): bool
    {
        return (string) $row->status === LedgerStatus::PENDING->value
            && (bool) (($row->raw_response_masked ?? [])[self::FLAG] ?? false);
    }

    /**
     * Record what a person found in PayPlus, and let the subscription move again.
     *
     * @param  string  $outcome  self::OUTCOME_TOOK | self::OUTCOME_DID_NOT
     */
    public function resolve(PaymentLedger $row, string $outcome, ?string $actor = null, ?string $transactionUid = null): bool
    {
        if (! self::isStuck($row) || ! in_array($outcome, [self::OUTCOME_TOOK, self::OUTCOME_DID_NOT], true)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($row, $outcome, $actor, $transactionUid): bool {
            // Re-read under the lock: two admins on the same row must not both
            // advance the plan's total for one charge.
            $fresh = PaymentLedger::query()->lockForUpdate()->find($row->getKey());

            if ($fresh === null || ! self::isStuck($fresh)) {
                return false;
            }

            $marks = array_merge((array) ($fresh->raw_response_masked ?? []), [
                self::FLAG => false,
                'resolved_at' => now()->toIso8601String(),
                'resolved_by' => $actor,
                'resolved_as' => $outcome,
            ]);

            $outcome === self::OUTCOME_TOOK
                ? $this->settleAsTaken($fresh, $marks, $transactionUid)
                : $this->settleAsNotTaken($fresh, $marks);

            Timeline::record(
                kind: Timeline::KIND_CHARGE_RECONCILED,
                details: [
                    'ledger_id' => (int) $fresh->getKey(),
                    'resolved_as' => $outcome,
                    'transaction_uid' => $transactionUid,
                ],
                planId: $fresh->plan_id === null ? null : (int) $fresh->plan_id,
                paymentId: $fresh->payment_id === null ? null : (int) $fresh->payment_id,
                actor: $actor,
                shopId: (int) $fresh->shop_id,
            );

            return true;
        });
    }

    /** @param array<string, mixed> $marks */
    private function settleAsTaken(PaymentLedger $row, array $marks, ?string $transactionUid): void
    {
        Ledger::transition($row, LedgerStatus::SUCCEEDED, [
            'payplus_transaction_uid' => $transactionUid ?: null,
            'raw_response_masked' => $marks,
        ]);

        $payment = $row->payment_id === null ? null : InstallmentPayment::query()->find($row->payment_id);

        if ($payment !== null && $payment->status !== PaymentStatus::SUCCEEDED) {
            $payment->markSucceeded($transactionUid, null, $marks);
        }

        $plan = $row->plan_id === null ? null : InstallmentPlan::query()->find($row->plan_id);

        if ($plan === null) {
            return;
        }

        // The money the pipeline never got to record.
        $plan->total_charged = round((float) $plan->total_charged + (float) $row->amount, 2);
        $plan->save();

        // The clock, for a recurring plan whose cycle this was. Installments keep
        // their schedule: the slice is paid, and the next slot follows the plan's
        // own dates rather than the day an admin happened to look.
        if ($plan->plan_kind === PlanKind::RECURRING && $plan->next_charge_at !== null
            && $plan->next_charge_at->isPast()) {
            $plan->next_charge_at = $plan->next_charge_at->addDay();
            $plan->save();
        }
    }

    /** @param array<string, mixed> $marks */
    private function settleAsNotTaken(PaymentLedger $row, array $marks): void
    {
        Ledger::transition($row, LedgerStatus::FAILED, [
            'failure_code' => 'unresolved_attempt',
            'failure_message' => 'Resolved by a person: PayPlus never took this charge.',
            'raw_response_masked' => $marks,
        ]);

        // The slot goes back to being RETRYABLE — not `failed`.
        //
        // `failed` with no retry date is how the pipeline says "we gave up on
        // this cycle", and nextSequenceFor() reads exactly that to decide the
        // next cycle deserves a fresh slot. Marking this one failed would
        // therefore have the next attempt open a SECOND slot for the same cycle,
        // which is the opposite of what a person answering "it never took" wants.
        // retry_scheduled, due now, puts the same debt back on the same slot.
        $payment = $row->payment_id === null ? null : InstallmentPayment::query()->find($row->payment_id);

        if ($payment !== null && $payment->status !== PaymentStatus::SUCCEEDED) {
            $payment->forceFill(['next_retry_at' => now()])->save();
            $payment->transitionTo(PaymentStatus::RETRY_SCHEDULED);
        }
    }
}
