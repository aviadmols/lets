<?php

namespace App\Domain\Installments;

use App\Domain\Installments\Jobs\RunTokenRecoveryJob;
use App\Domain\Installments\Models\TokenRecoveryResult;
use App\Domain\Installments\Models\TokenRecoveryRun;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Support\PlatformContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks a "find saved cards" run off the web request, one member at a time.
 *
 * WHY THIS IS NOT THE BULK EDITOR. BulkEditRunner commits a whole chunk inside
 * one transaction, which is exactly right for column writes and exactly wrong
 * here: every member costs up to thirteen HTTP calls to PayPlus, and holding a
 * database transaction open across external network I/O turns a slow gateway into a
 * pile of stuck connections. So the shape is inverted — the calls happen with no
 * transaction held, and a SHORT transaction then commits that member's counters
 * together with the cursor.
 *
 * ONE MEMBER PER COMMIT, which is what makes a killed worker cheap. The cursor
 * advances only after PayPlus answered and the row was written, so a retry redoes
 * at most one member, and redoing one is harmless: recover() probes the token we
 * now hold FIRST and returns ROUTE_ALREADY_VALID without swapping anything. In
 * recover-and-charge mode a redo re-dispatches a ChargeJob, which the four
 * idempotency layers (deterministic key, ledger pre-check, charge_in_flight, the
 * job's own unique lock) collapse back into one charge. The only cost of a retry
 * is a report that counts a member as "already valid" instead of "fixed".
 *
 * THE RUN CAN BE STOPPED. Status is re-read before every member, so a merchant
 * who started a hundred and changed their mind stops within one member rather
 * than when the run would have ended anyway.
 */
final class TokenRecoveryRunner
{
    // === CONSTANTS ===
    /**
     * Members one job invocation handles before re-dispatching itself.
     *
     * Deliberately modest. Thirteen calls per member means fifteen members can be
     * two hundred round trips, and a job that reaches its timeout mid-member is a
     * worse trade than one extra queue hop.
     */
    public const MEMBERS_PER_JOB = 15;

    /**
     * The most members one run may hold.
     *
     * Not a performance wall — the queue would happily walk more — but a wall on
     * how much a single click may set in motion when the mode also takes money.
     */
    public const MAX_RUN_SIZE = 2000;

    public function __construct(
        private readonly ImportedTokenRecovery $recovery,
    ) {}

    /**
     * Accept a selection and hand it to the queue.
     *
     * @param  list<int>  $planIds  in the order the merchant sees them
     */
    public function start(Shop $shop, array $planIds, string $mode): TokenRecoveryRun
    {
        $ids = array_values(array_unique(array_map('intval', $planIds)));
        $ids = array_slice($ids, 0, self::MAX_RUN_SIZE);

        $run = new TokenRecoveryRun;
        $run->forceFill([
            'shop_id' => $shop->getKey(),
            'mode' => in_array($mode, TokenRecoveryRun::MODES, true) ? $mode : TokenRecoveryRun::MODE_RECOVER,
            'plan_ids' => $ids,
            'status' => TokenRecoveryRun::STATUS_QUEUED,
            'total' => count($ids),
            'requested_by' => Auth::id(),
            // Frozen now: by the time a worker runs, the request that asked for
            // this is gone and PlatformContext would honestly answer "system".
            'actor' => PlatformContext::actingActor(),
        ])->save();

        RunTokenRecoveryJob::dispatch((int) $shop->getKey(), (int) $run->getKey());

        Log::info('token_recovery.started', [
            'run_id' => $run->getKey(),
            'shop_id' => $shop->getKey(),
            'mode' => $run->mode,
            'total' => $run->total,
        ]);

        return $run;
    }

    /**
     * Do a bounded slice of the run.
     *
     * @return bool true when there is nothing left to do (finished, stopped, or
     *              failed); false when the job should hand itself back to the queue
     */
    public function advance(int $runId, int $maxMembers = self::MEMBERS_PER_JOB): bool
    {
        // Tenant-scoped: a run id belonging to another shop resolves to null, and
        // the job carrying it simply has nothing to do.
        $run = TokenRecoveryRun::query()->find($runId);

        if ($run === null || $run->isFinished()) {
            return true;
        }

        try {
            if ((string) $run->status === TokenRecoveryRun::STATUS_QUEUED) {
                $run->forceFill([
                    'status' => TokenRecoveryRun::STATUS_RUNNING,
                    'started_at' => now(),
                ])->save();
            }

            $ids = $run->planIds();

            for ($i = 0; $i < $maxMembers; $i++) {
                $run->refresh();

                // Stopped by a merchant mid-run: everything committed stays, and
                // the report will say how many were never reached.
                if ((string) $run->status !== TokenRecoveryRun::STATUS_RUNNING) {
                    return true;
                }

                $cursor = (int) $run->cursor;

                if ($cursor >= count($ids)) {
                    $this->finish($runId, TokenRecoveryRun::STATUS_COMPLETED);

                    return true;
                }

                $this->member($run, $ids[$cursor], $cursor);
            }
        } catch (Throwable $e) {
            $this->markFailed($runId, $e);

            return true;
        }

        return false; // more to walk — the job re-dispatches itself
    }

    /**
     * ONE member: ask PayPlus, then commit what it said.
     *
     * The network happens first and alone. The write that follows is one short
     * transaction holding the cursor and the counters together, so the report can
     * never claim a member was processed without their result being in it.
     */
    private function member(TokenRecoveryRun $run, int $planId, int $cursor): void
    {
        $plan = InstallmentPlan::query()
            ->with(['paymentMethod', 'latestPayment'])
            ->find($planId);

        $delta = [];
        $name = null;
        $queueCharge = false;
        $outcome = null;
        $bucket = TokenRecoveryResult::OUTCOME_SKIPPED;

        if ($plan === null) {
            // Deleted between the click and the worker. Not an error; not a fix.
            $delta['skipped'] = 1;
        } elseif ($plan->status->isTerminal()) {
            // A cancelled or completed plan is not a debt anybody may collect.
            $delta['skipped'] = 1;
        } elseif ($plan->paymentMethod === null) {
            // No card row — there is nothing to re-point, and asking PayPlus about
            // it would spend a call to learn that.
            $delta['skipped'] = 1;
        } elseif ($run->chargesMoney()
            && (! $this->tokenInDoubt($plan) || $this->cardChangedSinceTheDecline($plan))) {
            /*
             * NOT PROBED, AND CHARGED ANYWAY. Two ways to land here.
             *
             * ONE — the token is not in doubt: it is vaulted and the last decline
             * was the issuer refusing a card it recognises. A lookup would spend a
             * call to be told the token is fine, and — the part that matters — a
             * lookup that CANNOT RUN (a shop with no PayPlus discovery configured,
             * a gateway that is down) returns "nothing found", which would then
             * stop a charge that was always going to be made on the card we
             * already hold. The money is still owed, so it is asked for.
             *
             * TWO — THE CARD HAS ALREADY BEEN REPLACED since that decline, and this
             * is the bug this branch was widened to kill. A merchant found the new
             * cards in one pass and pressed "find and charge" in the next; the
             * probe went looking for a SECOND replacement, found none, and the run
             * read "nothing found" as "no card to charge" — refusing to bill the
             * very card it had just attached a minute earlier. Four members were
             * fixed and nobody was charged.
             *
             * The old decline describes a card that is no longer there, so it is
             * not evidence about this one. Charge it.
             *
             * Find-cards mode probes everyone regardless, because there the report
             * IS the product and "already valid" is a real answer a merchant needs.
             */
            $delta['not_probed'] = 1;
            $bucket = TokenRecoveryResult::OUTCOME_NOT_PROBED;
            $queueCharge = true;
        } else {
            $outcome = $this->recovery->recover($plan);

            if ($outcome['applied'] ?? false) {
                $delta['fixed'] = 1;
                $bucket = TokenRecoveryResult::OUTCOME_FIXED;

                if ($outcome['recurring_live'] ?? false) {
                    // Recovered, and PayPlus is billing them on its own schedule.
                    // Charging from here would take their money twice, so this one
                    // is NAMED rather than counted.
                    $name = $plan->customerLabel();
                } else {
                    $queueCharge = true;
                }
            } else {
                $bucket = ($outcome['route'] ?? null) === ImportedTokenRecovery::ROUTE_ALREADY_VALID
                    ? TokenRecoveryResult::OUTCOME_ALREADY_VALID
                    : TokenRecoveryResult::OUTCOME_NONE;

                match (true) {
                    ($outcome['route'] ?? null) === ImportedTokenRecovery::ROUTE_ALREADY_VALID => $delta['already_valid'] = 1,
                    ($outcome['detail'] ?? null) === 'no_last_four_to_match_on' => $delta['no_last_four'] = 1,
                    ($outcome['detail'] ?? null) === 'no_card_matched' => $delta['ambiguous'] = 1,
                    default => $delta['not_found'] = 1,
                };

                // The token we hold is fine and the ISSUER said no. There is still
                // money owed, so in charge mode it is worth asking for again.
                $queueCharge = ($outcome['route'] ?? null) === ImportedTokenRecovery::ROUTE_ALREADY_VALID;
            }
        }

        if ($queueCharge && $run->chargesMoney() && $plan !== null) {
            // The scheduler's own job: unique per plan, tenant-bound, every
            // orchestrator law intact. It loads the plan fresh, including the card
            // this run may have just re-pointed.
            ChargeJob::dispatch(
                (int) $run->shop_id,
                (int) $plan->getKey(),
                ($plan->isRecurring() ? PaymentType::RECURRING : PaymentType::INSTALLMENT)->value,
            );
            $delta['charges_queued'] = 1;
        }

        $this->record($run, $planId, $bucket, $outcome);

        $this->commit((int) $run->getKey(), $cursor, $delta, $name);
    }

    /**
     * Is this plan's token provably in doubt — worth a lookup call to PayPlus?
     *
     * Two shapes: an imported token reference that was never vaulted (no PayPlus
     * customer uid), and a decline a stale token can actually cause. A plan in
     * neither shape has a working card that the ISSUER declined, and swapping its
     * token would fix nothing while destroying a good one.
     *
     * The same predicate the subscription page's button uses, so the screen and
     * the worker cannot disagree about who is worth asking about.
     */
    /**
     * Write what this member's lookup actually learned, and which cards it saw.
     *
     * Outside the counter transaction on purpose: a report row is a record, not a
     * ledger, and failing to write one must never cost the member's committed
     * result or stall the cursor. If it throws, the pass carries on and the run's
     * counters stay true — the explanation is what is lost, not the work.
     *
     * @param  array<string, mixed>|null  $outcome
     */
    private function record(TokenRecoveryRun $run, int $planId, string $bucket, ?array $outcome): void
    {
        try {
            $result = new TokenRecoveryResult;
            $result->forceFill([
                'shop_id' => (int) $run->shop_id,
                'run_id' => (int) $run->getKey(),
                'plan_id' => $planId,
                'outcome' => $bucket,
                'detail' => $outcome === null ? null : mb_substr((string) ($outcome['detail'] ?? ''), 0, 64),
                'candidates' => $outcome['candidates'] ?? null,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('token_recovery.result_not_recorded', [
                'run_id' => $run->getKey(),
                'plan_id' => $planId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Is the card we hold a DIFFERENT one from the card that was declined?
     *
     * The decline message is the only evidence we have about a card, and it goes
     * stale the moment the card is swapped — for a member whose token was
     * re-pointed yesterday, "כרטיס חסום" describes an instrument that is no longer
     * attached to anything. Treating it as current is what made a run fix four
     * members' cards and then charge none of them.
     *
     * Compared by timestamp rather than by remembering which token was declined,
     * because the ledger records the attempt and the method records the card, and
     * their two clocks already answer the question: a card touched more recently
     * than the last attempt cannot be the card that attempt was refused on.
     *
     * The imprecision is admitted and bounded: an unrelated edit to the card row
     * (a backfilled last-4, say) also moves that timestamp, and the cost is one
     * charge attempt that gets declined — the same outcome as not trying, minus a
     * subscription left unbilled because we were too clever.
     */
    private function cardChangedSinceTheDecline(InstallmentPlan $plan): bool
    {
        $cardTouched = $plan->paymentMethod?->updated_at;

        if ($cardTouched === null) {
            return false;
        }

        $lastAttempt = $plan->latestPayment?->updated_at;

        // Never charged here at all (a migrated member imported in arrears) — so
        // there is no decline for this card to be newer than.
        if ($lastAttempt === null) {
            return true;
        }

        return $cardTouched->greaterThan($lastAttempt);
    }

    private function tokenInDoubt(InstallmentPlan $plan): bool
    {
        if ($plan->paymentMethod?->payplus_customer_uid === null) {
            return true;
        }

        return ImportedTokenRecovery::declineIsRecoverable($plan->latestPayment?->failure_message);
    }

    /**
     * Commit one member's result: counters, the double-billing name, and the
     * cursor — together, or not at all.
     *
     * @param  array<string, int>  $delta
     */
    private function commit(int $runId, int $cursor, array $delta, ?string $name): void
    {
        DB::transaction(function () use ($runId, $cursor, $delta, $name): void {
            // The run row is the baton. Locking serialises a queue retry racing
            // the attempt it was meant to replace; without it both would read the
            // same cursor and count the same member twice.
            $run = TokenRecoveryRun::query()->lockForUpdate()->find($runId);

            if ($run === null || (int) $run->cursor !== $cursor) {
                return; // somebody else already committed this member
            }

            $fill = [
                'cursor' => $cursor + 1,
                'processed' => (int) $run->processed + 1,
            ];

            foreach ($delta as $column => $increment) {
                $fill[$column] = (int) $run->{$column} + $increment;
            }

            if ($name !== null) {
                $names = (array) ($run->double_billing ?? []);
                $names[] = $name;
                $fill['double_billing'] = $names;
            }

            $run->forceFill($fill)->save();
        });
    }

    /** The merchant's Stop. Everything already committed stays committed. */
    public function cancel(int $runId): void
    {
        TokenRecoveryRun::query()
            ->whereKey($runId)
            ->whereIn('status', [TokenRecoveryRun::STATUS_QUEUED, TokenRecoveryRun::STATUS_RUNNING])
            ->update([
                'status' => TokenRecoveryRun::STATUS_CANCELLED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function finish(int $runId, string $status): void
    {
        TokenRecoveryRun::query()
            ->whereKey($runId)
            ->whereIn('status', [TokenRecoveryRun::STATUS_QUEUED, TokenRecoveryRun::STATUS_RUNNING])
            ->update([
                'status' => $status,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Say it ON THE ROW, not only in a log.
     *
     * A run left reading "running" forever is the worst outcome: the merchant
     * cannot tell whether their hundred members were asked about, and the screen
     * would poll a worker that is never coming back. The cursor stays where it
     * committed, so the report still says how far it got.
     */
    private function markFailed(int $runId, Throwable $e): void
    {
        Log::error('token_recovery.failed', [
            'run_id' => $runId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        TokenRecoveryRun::query()
            ->whereKey($runId)
            ->whereIn('status', [TokenRecoveryRun::STATUS_QUEUED, TokenRecoveryRun::STATUS_RUNNING])
            ->update([
                'status' => TokenRecoveryRun::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
