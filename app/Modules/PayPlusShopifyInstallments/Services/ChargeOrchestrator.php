<?php

namespace App\Modules\PayPlusShopifyInstallments\Services;

use App\Domain\Billing\Contracts\DocumentPolicy;
use App\Domain\Billing\Contracts\DocumentPolicyInput;
use App\Domain\Billing\CycleAmountResolver;
use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Portal\PortalSignedUrlService;
use App\Events\ChargeFailed;
use App\Events\ChargeSucceeded;
use App\Mail\ManualRecurringPaymentMail;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\MerchantMailSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\ResponseMasker;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Orders\PlatformOrderStrategyFactory;
use App\Services\Shopify\Orders\ShopifyOrderStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The charge pipeline (the spine). Ported + multi-tenant-refactored from the
 * reference engine's ChargeOrchestrator. Earned-in-production shape:
 *
 *   1. PHASE A, in a short transaction under the plan's row lock: a succeeded
 *      ledger row for the key short-circuits (no PayPlus call); an unsettled
 *      `pending` row means a sibling attempt is in flight and this one stands
 *      down; then the gates (live-charging switch, manual mode, consent) and the
 *      `pending` ledger row, which COMMITS before anything is charged.
 *   2. PHASE B, holding nothing: charge via PayPlusGatewayFactory::for($plan->shop)
 *      — per-shop creds, never config(). A death here leaves the committed
 *      pending row as the reconcilable trace, which is what it is for.
 *   3. PHASE C, in a second short transaction: re-lock, then record the outcome
 *      — ledger, slot, plan state, retry clock. On failure the cycle is asked for
 *      daily until the merchant's attempt budget runs out, then skipped.
 *   4. PHASE D, after the commit: the document, the store order, the card label
 *      and the notifications. Everything that reaches outside this database is
 *      here, where it cannot unwind a charge that has already happened.
 *
 * The gateway call used to sit INSIDE one transaction spanning all of this. See
 * charge() for what that cost and how the split pays for itself.
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Services/ChargeOrchestrator.php
 *
 * ChargeSucceeded/ChargeFailed events are fired AFTER the ledger + Timeline are
 * written (Phase 3.5) — the email listeners are tenant-bound + non-blocking.
 * TODO(phase 4): ShopifyOrderStrategy::createFulfillableOrder for recurring cycles
 * + ReleaseFulfillmentIfFullyPaidJob on installments completion;
 * TODO(phase 3.x): OrderChargeEligibility (cancelled/closed order); the manual-mode
 * email short-circuit (meta.manual_payment_sent_at) is wired in handleManualMode().
 *
 * TODO(review #4, #6): see docs/reviews/phase-2-3.md — deferred gatekeeper
 * suggestions (not blockers) to be addressed in a follow-up pass.
 */
final class ChargeOrchestrator
{
    // === CONSTANTS ===
    /** Daily charge attempts before the cycle is given up on. @see config/payplus.php */
    private const MAX_ATTEMPTS_FALLBACK = 10;

    /**
     * A safety stop on rolling a stale schedule forward.
     *
     * A plan whose date is years behind (a bad import, a long-dormant row) must
     * land on a future date without spinning: past this many cycles we take the
     * date we have reached and move on.
     */
    private const MAX_CYCLE_ROLL_FORWARD = 240;

    /**
     * How long an unsettled `pending` ledger row counts as a charge still in
     * flight. Comfortably past the gateway's own 30-second timeout, so a slow
     * PayPlus is never mistaken for a dead worker; short enough that a genuinely
     * stuck row does not hold the next scheduled attempt for long.
     *
     * @see config/payplus.php
     */
    private const IN_FLIGHT_MINUTES = 15;

    /**
     * The Shopify order strategy is OPTIONAL + nullable so the billing engine
     * stays decoupled from the Shopify boundary (and unit tests can run the money
     * pipeline without any Shopify wiring). When bound (by the shopify-integration
     * service provider), phase D materializes Shopify state AFTER the ledger is
     * succeeded AND committed — a Shopify hiccup never rolls back the money truth.
     */
    public function __construct(
        private readonly DocumentPolicy $documentPolicy,
        private readonly ?ShopifyOrderStrategy $shopifyOrders = null,
        private readonly CycleAmountResolver $cycleAmounts = new CycleAmountResolver,
    ) {}

    /**
     * The one piece of manual-mode work phase A cannot finish itself: an email
     * to an SMTP relay, handed across the commit boundary to charge().
     *
     * Set and consumed within a single charge() call, and cleared as it is read.
     *
     * @var array{plan_id: int, invoice_url: string}|null
     */
    private ?array $deferredMail = null;

    /**
     * Charge one plan for a given payment type. Tenant must already be bound by
     * the job middleware; $plan is resolved under that scope.
     *
     * FOUR PHASES, and the boundaries between them are the money-safety design:
     *
     *   A. decide, and commit the `pending` ledger row       (one short txn)
     *   B. move the money                                    (NO txn, NO lock)
     *   C. record what happened                              (one short txn)
     *   D. side effects — store order, document, mail, events (after everything)
     *
     * The gateway call used to sit inside a single transaction that also held
     * the plan's row lock, which cost two things. A worker killed between the
     * charge and the commit rolled back the very `pending` row that exists to
     * survive that, so a charge that really happened left NOTHING to reconcile.
     * And a PayPlus round trip is a 30-second timeout: a connection and a row
     * lock were held for its whole length, on the app's hottest table.
     * UpsellChargeService already carries this shape and the scar that taught it.
     *
     * What the split costs, and how it is paid for: with the lock released
     * across phase B, two triggers can now interleave instead of queueing, so
     * phase A refuses to start a charge while another is IN FLIGHT for the same
     * key (an unsettled `pending` row younger than IN_FLIGHT_MINUTES). That
     * restores exactly the serialisation the lock used to give, without holding
     * a database transaction open across somebody else's network.
     */
    public function charge(int $planId, PaymentType $type): ChargeOutcome
    {
        // === PHASE A — decide, and commit the pending ledger row ===
        $prepared = DB::transaction(fn (): ChargeOutcome|array => $this->prepare($planId, $type));

        if ($prepared instanceof ChargeOutcome) {
            // A settled answer needs no money. Manual mode still owes an email,
            // and that is a side effect like any other: it runs out here.
            $this->runDeferredMail($planId);

            return $prepared;
        }

        /** @var InstallmentPlan $plan */
        $plan = $prepared['plan'];
        /** @var PaymentLedger $ledger */
        $ledger = $prepared['ledger'];

        // === PHASE B — the money. Outside every transaction, holding no lock. ===
        // A death here leaves the committed `pending` row behind: the reconcilable
        // trace, which is the whole reason that row is written before the call.
        $result = PayPlusGatewayFactory::for($plan->shop)->chargeWithReference(
            $plan->activePaymentMethod(),
            $prepared['amount'],
            $prepared['key'],
            ['currency' => $plan->currency],
        );

        // === PHASE C — record the outcome, in its own short transaction. ===
        $settled = DB::transaction(function () use ($planId, $prepared, $result, $type): array {
            // Re-lock and re-read: phase B released the lock, and everything
            // written here must be written against what is true NOW.
            $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($planId);
            $payment = $prepared['payment']->fresh();
            $ledger = $prepared['ledger']->fresh();

            $plan->forceFill(['last_charge_attempt_at' => now()])->save();

            return $result->success
                ? $this->onSuccess($plan, $payment, $ledger, $result, $type)
                : $this->onFailure($plan, $payment, $ledger, $result);
        });

        // === PHASE D — side effects, after the money truth is committed. ===
        $this->runAfterEffects($settled, $result, $type, $ledger);

        return $settled['outcome'];
    }

    /**
     * PHASE A. Every gate a charge must pass, and the `pending` ledger row that
     * commits before the gateway is called.
     *
     * Returns a settled ChargeOutcome when there is nothing to charge, or the
     * bag phase B and C need.
     *
     * @return ChargeOutcome|array{plan: InstallmentPlan, payment: InstallmentPayment, ledger: PaymentLedger, key: string, amount: float}
     */
    private function prepare(int $planId, PaymentType $type): ChargeOutcome|array
    {
        // Row lock: two simultaneous triggers serialise here. BelongsToShop
        // scopes the lookup to the bound tenant.
        $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($planId);
        $shopId = (int) $plan->shop_id;

        $key = $this->idempotencyKeyFor($plan, $type);

        // Idempotent short-circuit — a succeeded ledger row means done.
        if (Ledger::hasSucceeded($shopId, $key)) {
            return ChargeOutcome::skipped('already_succeeded', $key);
        }

        // A charge for this key is ALREADY MOVING. The row lock no longer spans
        // the gateway call (see charge()), so this is what keeps a scheduler tick
        // and a merchant's "charge now" from both reaching PayPlus for one debt.
        // Judged on the row's own age: only an unsettled row younger than the
        // window is somebody else mid-flight. An OLDER pending row is a charge
        // whose outcome nobody ever learned, and that is a different problem with
        // a different answer — it is not treated as in-flight here.
        $pending = $this->unsettledRow($shopId, $key);

        if ($pending !== null && $this->isInFlight($pending)) {
            Timeline::record(
                kind: Timeline::KIND_CHARGE_IN_FLIGHT,
                details: ['type' => $type->value, 'key' => $key],
                planId: $plan->getKey(),
                shopId: $shopId,
            );

            return ChargeOutcome::skipped('charge_in_flight', $key);
        }

        // A `pending` row OLDER than the in-flight window means we asked PayPlus
        // for money and never learned the answer — a worker killed mid-charge, a
        // deploy, an OOM. The card may well have been charged.
        //
        // We do NOT ask again. This is the same doctrine `issued_documents`
        // already runs on, for the same reason: an attempt whose outcome is
        // unknown is never re-posted, because the two mistakes are not equal. A
        // missing charge is a button a human presses once they have looked; a
        // DOUBLE charge is somebody's money taken twice, a refund, and a
        // conversation about it. So the row is marked for a person to resolve and
        // this cycle stops here — loudly, on the plan's own timeline, not in a
        // log that rotates.
        if ($pending !== null) {
            $this->flagForReconcile($pending, $plan, $type, $key);

            return ChargeOutcome::skipped('needs_reconcile', $key);
        }

        // THE LIVE-CHARGING SWITCH. A merchant mid-migration wants their plans
        // active and readable long before they want a saved card touched, and
        // this is the one place every charge passes through — scheduler, manual
        // retry, upsell alike — so it is the only place the answer can be
        // enforced rather than merely hoped for.
        //
        // It fails CLOSED and CHEAP: before the attempt event, before a payment
        // row, before a ledger row, before the gateway. Nothing is written that
        // a resumed shop would then have to reconcile. The skip IS recorded on
        // the plan's timeline, because a subscription that quietly did not bill
        // is exactly the thing a merchant must be able to discover later.
        if (! MerchantBillingSettings::current()->chargingIsLive()) {
            Timeline::record(
                kind: Timeline::KIND_CHARGING_PAUSED,
                details: ['type' => $type->value, 'key' => $key],
                planId: $plan->getKey(),
                shopId: $shopId,
            );

            return ChargeOutcome::skipped('charging_paused', $key);
        }

        Timeline::record(
            kind: Timeline::KIND_CHARGE_ATTEMPT_STARTED,
            details: ['type' => $type->value, 'key' => $key],
            planId: $plan->getKey(),
            shopId: $shopId,
        );

        // Manual-payment plans don't auto-charge a token (TODO: email invoice).
        if ($plan->requires_manual_payment || $plan->activePaymentMethod() === null) {
            return $this->handleManualMode($plan, $type, $key);
        }

        // Money-safety law: NO saved-token charge without a stored consent row
        // (shop, customer, context-matching-the-plan-kind). Fail CLOSED — no
        // ledger row, no gateway call — and leave the plan for admin attention.
        if (! $this->hasConsent($plan)) {
            Timeline::record(
                kind: Timeline::KIND_CONSENT_MISSING,
                details: [
                    'type' => $type->value,
                    'key' => $key,
                    'consent_context' => $this->consentContextFor($plan),
                ],
                planId: $plan->getKey(),
                shopId: $shopId,
            );

            return ChargeOutcome::skipped('no_consent', $key);
        }

        $payment = $this->findOrCreatePayment($plan, $type);
        if ($payment->status === PaymentStatus::SUCCEEDED) {
            return ChargeOutcome::skipped('payment_already_succeeded', $key);
        }

        $amount = round((float) $payment->amount, 2);

        // Ledger opens PENDING before the side effect — and COMMITS before it,
        // now that the gateway call has left this transaction.
        $ledger = Ledger::open(
            shopId: $shopId,
            chargeContext: $type->toChargeContext()->value,
            idempotencyKey: $key,
            amount: $amount,
            currency: (string) ($plan->currency ?? config('payplus.currency', 'ILS')),
            attributes: [
                'plan_id' => $plan->getKey(),
                // The SLOT this row is the money for. A refund walks back
                // through it to mark the slice refunded and to give the plan
                // its money back; without it a refunded plan still reports
                // itself fully paid.
                'payment_id' => $payment->getKey(),
                'payment_method_id' => $plan->payment_method_id,
                'customer_id' => $plan->customer_id,
                'shopify_customer_id' => $plan->shopify_customer_id,
                'shopify_order_id' => $plan->shopify_order_id,
            ],
        );

        return [
            'plan' => $plan,
            'payment' => $payment,
            'ledger' => $ledger,
            'key' => $key,
            'amount' => $amount,
        ];
    }

    /**
     * The `pending` ledger row for this key, if there is one.
     *
     * `pending` means exactly one thing: the gateway was called and the answer
     * never came back. What that means for THIS attempt depends only on the
     * row's age — see isInFlight().
     */
    private function unsettledRow(int $shopId, string $key): ?PaymentLedger
    {
        $row = Ledger::find($shopId, $key);

        return $row !== null && (string) $row->status === LedgerStatus::PENDING->value
            ? $row
            : null;
    }

    /**
     * Is that row a sibling attempt still running, rather than a stuck one?
     *
     * Younger than the window, another trigger is at the gateway right now and
     * this one must not start beside it. Older, nobody is coming back with the
     * answer, and re-asking would risk charging the same debt twice.
     */
    private function isInFlight(PaymentLedger $row): bool
    {
        $minutes = max(1, (int) config('payplus.charge_in_flight_minutes', self::IN_FLIGHT_MINUTES));

        return $row->created_at !== null
            && $row->created_at->gt(now()->subMinutes($minutes));
    }

    /**
     * Mark a stuck charge for a human, once.
     *
     * The ledger row is left exactly as it is — `pending` IS the honest status,
     * and the state machine has no edge out of it that does not claim to know
     * something we do not. The flag rides in the row's masked-response bag and on
     * the plan's timeline, which is where a merchant looks.
     */
    private function flagForReconcile(PaymentLedger $row, InstallmentPlan $plan, PaymentType $type, string $key): void
    {
        $raw = (array) ($row->raw_response_masked ?? []);

        if (($raw['needs_reconcile'] ?? false) !== true) {
            $row->forceFill([
                'raw_response_masked' => array_merge($raw, [
                    'needs_reconcile' => true,
                    'flagged_at' => now()->toIso8601String(),
                ]),
            ])->save();

            Timeline::record(
                kind: Timeline::KIND_CHARGE_NEEDS_RECONCILE,
                details: [
                    'type' => $type->value,
                    'key' => $key,
                    'ledger_id' => (int) $row->getKey(),
                    'opened_at' => $row->created_at?->toIso8601String(),
                ],
                planId: $plan->getKey(),
                shopId: (int) $plan->shop_id,
            );
        }

        Log::warning('payplus.charge.needs_reconcile', [
            'shop_id' => (int) $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'ledger_id' => (int) $row->getKey(),
            'idempotency_key' => $key,
            'opened_at' => $row->created_at?->toIso8601String(),
        ]);
    }

    // === Success / failure branches ===

    /**
     * PHASE C (success). The money truth and the plan's state, and NOTHING that
     * reaches outside this database: the store order, the document and the
     * notification all belong to phase D, where a failure cannot unwind a
     * committed charge.
     *
     * @return array{outcome: ChargeOutcome, plan: InstallmentPlan, payment: InstallmentPayment, is_final: bool, is_first_payment: bool}
     */
    private function onSuccess(
        InstallmentPlan $plan,
        InstallmentPayment $payment,
        PaymentLedger $ledger,
        GatewayResult $result,
        PaymentType $type,
    ): array {
        $masked = ResponseMasker::mask($result->raw);

        // First-payment detection BEFORE this slot is marked succeeded: a plan with
        // no prior succeeded payment is welcoming its customer with this charge.
        // Drives the welcome-vs-confirmation choice in the ChargeSucceeded listener.
        $isFirstPayment = $plan->payments()
            ->where('status', PaymentStatus::SUCCEEDED->value)
            ->count() === 0;

        // Ledger → succeeded. NEVER persist '' for the uid (unique-index collision).
        Ledger::transition($ledger, LedgerStatus::SUCCEEDED, [
            'payplus_transaction_uid' => $result->transactionUid ?: null,
            'payplus_document_uid' => $result->documentUid ?: null,
            'raw_response_masked' => $masked,
            'failure_code' => null,
            'failure_message' => null,
        ]);

        $payment->markSucceeded($result->transactionUid, $result->approvalNumber, $masked);

        // The card label is refreshed in phase D (runAfterEffects): PayPlus has
        // just described the card it charged, which is the one moment that
        // description can be trusted — but it is bookkeeping standing next to
        // money, and it belongs on the far side of the commit.

        // Advance plan money + state INSIDE the txn, BEFORE any external side effect.
        $plan->total_charged = round((float) $plan->total_charged + (float) $payment->amount, 2);
        $plan->save();

        $isFinal = false;

        if ($plan->plan_kind === PlanKind::INSTALLMENTS && $plan->isFullyPaid()) {
            $isFinal = true;
            $plan->next_charge_at = null;
            $plan->save();
            $this->ensureActiveThen($plan, PlanStatus::COMPLETED);

            Timeline::record(Timeline::KIND_PLAN_COMPLETED, ['plan_id' => $plan->getKey()], $plan->getKey(), shopId: $plan->shop_id);
        } elseif ($plan->plan_kind === PlanKind::RECURRING) {
            // Recurring never completes — advance the clock by one cycle.
            $plan->next_charge_at = $this->advanceNextChargeAt($plan);
            $plan->save();

            // A recurring plan whose FIRST payment was collected by this engine (no
            // hosted page, no external checkout — the account-offer path charges a
            // saved token directly) is still sitting in awaiting_first_payment. The
            // payment just landed, so it is active: promote it here rather than
            // leaving a live, billing subscription labelled "awaiting" forever. The
            // checkout paths never reach this branch in that state — PlanActivation
            // Service has already activated them.
            if ($plan->status === PlanStatus::AWAITING_FIRST_PAYMENT) {
                $plan->transitionTo(PlanStatus::ACTIVE, ['action' => 'first_payment_succeeded']);
            }

            // The debt is paid — dunning is over and the subscription is plainly
            // healthy again. Recorded, so "when did they come back?" is answerable.
            if ($plan->status === PlanStatus::AWAITING_PAYMENT) {
                $plan->transitionTo(PlanStatus::ACTIVE, ['action' => 'payment_recovered']);
            }
        } else {
            // Installments, not final — the slice landed, so dunning is over.
            if ($plan->status === PlanStatus::AWAITING_PAYMENT) {
                $plan->transitionTo(PlanStatus::ACTIVE, ['action' => 'payment_recovered']);
            }

            // Schedule the next slot + update parent.
            $plan->next_charge_at = $this->advanceNextChargeAt($plan);
            $plan->save();
        }

        // The one-time next-order override (W25) is cleared in phase D, AFTER the
        // store order it also shapes has been written — it prices the cycle
        // (amountFor) and shapes the order (onRecurring), and it is spent only
        // once both have happened. Clearing it here would hand the order strategy
        // a plan that no longer knows about it, and the order would silently come
        // out at the ordinary price.

        Timeline::record(
            kind: Timeline::KIND_CHARGE_SUCCEEDED,
            details: [
                'amount' => (float) $payment->amount,
                'transaction_uid' => $result->transactionUid,
                'is_final' => $isFinal,
            ],
            planId: $plan->getKey(),
            paymentId: $payment->getKey(),
            shopId: $plan->shop_id,
        );

        // The document, the store order and the notification are phase D — see
        // runAfterEffects(). None of them may run while this transaction is open:
        // each one reaches outside the database, and the money is already true.
        return [
            'outcome' => ChargeOutcome::succeeded($ledger->idempotency_key, $result->transactionUid, $isFinal),
            'plan' => $plan,
            'payment' => $payment,
            'is_final' => $isFinal,
            'is_first_payment' => $isFirstPayment,
        ];
    }

    /**
     * PHASE D. Everything that reaches outside this database, run only once the
     * money truth is committed — so none of it can unwind a charge that happened.
     *
     * @param  array{outcome: ChargeOutcome, plan: InstallmentPlan, payment: InstallmentPayment, is_final?: bool, is_first_payment?: bool, will_retry?: bool}  $settled
     */
    private function runAfterEffects(array $settled, GatewayResult $result, PaymentType $type, PaymentLedger $ledger): void
    {
        $plan = $settled['plan'];
        $payment = $settled['payment'];

        if (! $settled['outcome']->isSucceeded()) {
            // Notification — fired AFTER the ledger row + Timeline are written. The
            // failed-charge email tells the customer the reason + the next retry date.
            ChargeFailed::dispatch(
                (int) $plan->shop_id,
                $plan,
                $payment,
                $result->errorCode,
                $result->errorMessage,
                (bool) ($settled['will_retry'] ?? false),
            );

            return;
        }

        $isFinal = (bool) ($settled['is_final'] ?? false);

        // The card just told us what it is. Our stored expiry/brand/last-four are
        // written once at vaulting and never again — while the token keeps working
        // through a bank's renewal, which reissues the same card with a new date.
        // A label, not a credential: it cannot affect this or any future charge.
        $this->refreshCardLabel($plan, $result);

        // Documents — ONLY via the policy. The orchestrator never names a type.
        $this->maybeIssueDocument($plan, $type, $isFinal, $ledger);

        // Materialize store state — AFTER the ledger is succeeded + the plan
        // advanced. Owned by each platform's order strategy; the orchestrator only
        // knows the interface. Installments-final releases fulfillment; recurring
        // creates a new fulfillable order; deposit/first installment update the
        // parent. A store failure is logged, never unwound (the money already moved
        // and is recorded in the ledger).
        $this->materializePlatformOrder($plan, $type->toChargeContext(), $isFinal);

        // The one-time next-order override (W25) is NOW fully consumed: it priced
        // the charge and it shaped the order above. Clear it so the next cycle
        // reverts to normal. Only on SUCCESS — a failed attempt keeps it, and the
        // retry reuses the already-stamped slot amount.
        //
        // Last, deliberately. A worker that dies before this point did not write
        // the order either, so the cycle needs a human anyway (there is a Timeline
        // flare for that) and the surviving override is consistent with "phase D
        // never ran". Clearing it earlier would instead produce a quietly wrong
        // order at the ordinary price, with nothing at all to notice it by.
        if ($plan->plan_kind === PlanKind::RECURRING && $plan->nextOrderOverride() !== null) {
            $plan->clearNextOrderOverride();
        }

        // Notification — fired AFTER the ledger row + Timeline are written (money
        // truth first; an email is never the reason a charge "happened"). The
        // listener is tenant-bound + wraps the send in try/catch so a mail failure
        // can never roll back or block the charge.
        ChargeSucceeded::dispatch(
            (int) $plan->shop_id,
            $plan,
            $payment,
            (bool) ($settled['is_first_payment'] ?? false),
            $isFinal,
        );
    }

    /**
     * Correct the stored card label from the charge response — best effort.
     *
     * Wrapped because it is bookkeeping standing next to money that has already
     * moved: the ledger is succeeded and the plan advanced before this runs, and
     * nothing about a description is worth risking that on. A failure is logged
     * and the charge stands.
     */
    private function refreshCardLabel(InstallmentPlan $plan, GatewayResult $result): void
    {
        try {
            $card = $result->cardInformation();
            $method = $plan->activePaymentMethod();

            if ($card === [] || $method === null || ! $method->refreshLabelFrom($card)) {
                return;
            }

            Log::info('payplus.card_label_refreshed', [
                'shop_id' => (int) $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'payment_method_id' => $method->getKey(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('payplus.card_label_refresh_failed', [
                'plan_id' => $plan->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PHASE C (failure). The ledger, the slot and the retry clock — and the
     * notification deferred to phase D, for the same reason as on success.
     *
     * @return array{outcome: ChargeOutcome, plan: InstallmentPlan, payment: InstallmentPayment, will_retry: bool}
     */
    private function onFailure(
        InstallmentPlan $plan,
        InstallmentPayment $payment,
        PaymentLedger $ledger,
        GatewayResult $result,
    ): array {
        $payment->attempt_count = (int) $payment->attempt_count + 1;
        $payment->failure_code = $result->errorCode;
        $payment->failure_message = $result->errorMessage;

        // The merchant's own policy when they have one — the screen that offers
        // these two numbers used to change nothing at all. Platform config is
        // the floor under a shop that has never opened that screen.
        $settings = MerchantBillingSettings::query()->where('shop_id', $plan->shop_id)->first();

        $maxAttempts = $settings?->maxChargeAttempts()
            ?? max(1, (int) config('payplus.retry_daily_attempts', self::MAX_ATTEMPTS_FALLBACK));
        $intervalHours = $settings?->retryIntervalHours()
            ?? max(1, (int) config('payplus.retry_interval_hours', 24));
        $willRetry = $payment->attempt_count < $maxAttempts;

        // The subscriber owes us this cycle, and we are still asking. That is a
        // live subscription with an unpaid cycle — not a dead one — so it is
        // said out loud in the plan's own status, from the FIRST failure and for
        // as long as the debt stands.
        $this->enterDunning($plan);

        if ($willRetry) {
            // One attempt a day, on the SAME slot and the same idempotency key.
            $payment->next_retry_at = now()->addHours($intervalHours);
            $payment->save();
            $payment->transitionTo(PaymentStatus::RETRY_SCHEDULED);

            Ledger::transition($ledger, LedgerStatus::FAILED, [
                'failure_code' => $result->errorCode,
                'failure_message' => $result->errorMessage,
                'raw_response_masked' => ResponseMasker::mask($result->raw),
            ]);
            Ledger::transition($ledger, LedgerStatus::RETRY_SCHEDULED);
        } else {
            // The days ran out. We stop asking for THIS cycle — and we do not
            // collect it later either: the slot is closed, and the plan is
            // pointed at its next ordinary renewal, in the future. The plan
            // stays in dunning so the merchant can see who owes what.
            $payment->next_retry_at = null;
            $payment->save();
            $payment->transitionTo(PaymentStatus::FAILED);

            Ledger::transition($ledger, LedgerStatus::FAILED, [
                'failure_code' => $result->errorCode,
                'failure_message' => $result->errorMessage,
                'raw_response_masked' => ResponseMasker::mask($result->raw),
            ]);

            $this->skipToNextCycle($plan);
        }

        Timeline::record(
            kind: $willRetry ? Timeline::KIND_CHARGE_RETRY_SCHEDULED : Timeline::KIND_CHARGE_FAILED,
            details: [
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
                'attempt' => $payment->attempt_count,
                'next_retry_at' => $payment->next_retry_at?->toIso8601String(),
            ],
            planId: $plan->getKey(),
            paymentId: $payment->getKey(),
            shopId: $plan->shop_id,
        );

        // The notification is phase D — see runAfterEffects().
        return [
            'outcome' => ChargeOutcome::failed($ledger->idempotency_key, $result->errorCode, $willRetry),
            'plan' => $plan,
            'payment' => $payment,
            'will_retry' => $willRetry,
        ];
    }

    // === Helpers ===

    /**
     * Hand off to the shop's PLATFORM order strategy when one is bound, AFTER the
     * ledger row is succeeded (the money truth). Wrapped so a store-side error never
     * propagates into the money pipeline. Shopify shops use the DI-injected strategy
     * (so Shopify stays byte-identical and the existing Shopify tests are untouched);
     * non-Shopify shops resolve their sibling via PlatformOrderStrategyFactory (null
     * until that platform's strategy ships → the engine runs decoupled for it). In
     * production the strategy should enqueue heavy store work on the `sync` queue.
     */
    private function materializePlatformOrder(InstallmentPlan $plan, ChargeContext $context, bool $isFinal): void
    {
        $strategy = $plan->shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? PlatformOrderStrategyFactory::for($plan->shop)
            : $this->shopifyOrders;

        if ($strategy === null) {
            return; // engine runs decoupled when this platform's boundary isn't bound
        }

        try {
            $strategy->materialize($plan, $context, $isFinal);
        } catch (\Throwable $e) {
            Log::error('platform.order_strategy.materialize_failed', [
                'plan_id' => $plan->getKey(),
                'shop_id' => $plan->shop_id,
                'platform' => $plan->shop->platform,
                'context' => $context->value,
                'is_final' => $isFinal,
                'error' => $e->getMessage(),
            ]);

            // ON THE TIMELINE, not only in a log that rotates: the money moved
            // and the merchant's store doesn't say so — every recurring cycle on
            // the pilot store was missing its WC order for WEEKS and nobody could
            // see it. The ledger stays the money truth; this event is the flare.
            Timeline::record(
                kind: Timeline::KIND_STORE_ORDER_FAILED,
                details: [
                    'context' => $context->value,
                    'reason' => mb_substr($e->getMessage(), 0, 300),
                ],
                planId: $plan->getKey(),
                shopId: (int) $plan->shop_id,
            );
        }
    }

    /**
     * Manual-payment mode: no saved token. Emails the merchant's invoice link and
     * short-circuits on meta.manual_payment_sent_at so the scheduler never
     * double-invoices a customer who has not yet paid last cycle's invoice. The
     * clock advances (recurring) without charging.
     */
    private function handleManualMode(InstallmentPlan $plan, PaymentType $type, string $key): ChargeOutcome
    {
        $alreadySent = (bool) (($plan->meta ?? [])['manual_payment_sent_at'] ?? false);

        // SHORT-CIRCUIT (scar tissue): a customer who has not paid last cycle's
        // emailed invoice must NOT get a second one. When the marker is set we only
        // advance the clock (recurring) — never re-invoice — until payment lands.
        if ($alreadySent) {
            if ($plan->plan_kind === PlanKind::RECURRING) {
                $plan->next_charge_at = $this->advanceNextChargeAt($plan);
                $plan->save();
            }

            Timeline::record(
                kind: 'manual_payment_pending',
                details: ['type' => $type->value, 'key' => $key],
                planId: $plan->getKey(),
                shopId: $plan->shop_id,
            );

            return ChargeOutcome::skipped('manual_mode', $key);
        }

        // First request this cycle: mark the marker (idempotency guard) + advance.
        $meta = (array) ($plan->meta ?? []);
        $meta['manual_payment_sent_at'] = now()->toIso8601String();
        $plan->meta = $meta;

        if ($plan->plan_kind === PlanKind::RECURRING) {
            $plan->next_charge_at = $this->advanceNextChargeAt($plan);
        }
        $plan->save();

        // Email the merchant's draft invoice link. The UNPAID-invoice draft-order
        // method is not built yet (ShopifyDraftOrderService only does the
        // completed-as-paid upsell child order), so the invoice URL is stubbed.
        // TODO(phase 3.x): ShopifyDraftOrderService::createManualPaymentInvoice($plan)
        // → unpaid draft → invoice_url; pass it to the mailable below.
        $invoiceUrl = $this->manualInvoiceUrlStub($plan);

        Timeline::record(
            kind: 'manual_payment_email_sent',
            details: ['type' => $type->value, 'key' => $key, 'invoice_url' => $invoiceUrl],
            planId: $plan->getKey(),
            shopId: $plan->shop_id,
        );

        // The send itself is a side effect reaching an SMTP relay, so it waits
        // for the commit like every other one — charge() runs it. The marker
        // above is what makes that safe: it is already written, so the customer
        // cannot be invoiced twice even if this attempt's mail never leaves.
        $this->deferredMail = ['plan_id' => (int) $plan->getKey(), 'invoice_url' => $invoiceUrl];

        return ChargeOutcome::skipped('manual_mode', $key);
    }

    /**
     * Send the manual-mode invoice email queued up by phase A, if there was one.
     *
     * Deliberately keyed by plan id and re-read here: the model that produced it
     * belonged to a transaction that has since committed, and this runs on the
     * far side of it.
     */
    private function runDeferredMail(int $planId): void
    {
        $deferred = $this->deferredMail;
        $this->deferredMail = null;

        if ($deferred === null || $deferred['plan_id'] !== $planId) {
            return;
        }

        $plan = InstallmentPlan::query()->find($planId);
        $recipient = $plan === null ? '' : $this->recipientFor($plan);

        if ($plan === null || $recipient === '') {
            return;
        }

        try {
            Mail::to($recipient)->send(
                new ManualRecurringPaymentMail(
                    shop: $plan->shop,
                    plan: $plan,
                    portalUrl: $this->portalUrlFor($plan),
                    invoiceUrl: $deferred['invoice_url'],
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('mail.manual_payment.send_failed', [
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stub for the manual-payment invoice URL until the unpaid-draft method lands.
     * Returns the portal URL (where the customer can pay) so the email's CTA is at
     * least live, never a dead link.
     */
    private function manualInvoiceUrlStub(InstallmentPlan $plan): string
    {
        return $this->portalUrlFor($plan) ?? '';
    }

    /** Recipient email for a plan's notifications. */
    private function recipientFor(InstallmentPlan $plan): string
    {
        return (string) ($plan->customer_email ?? '');
    }

    /**
     * The signed customer-portal URL for the plan, when one can be built. Phase 6.5:
     * prefer the PER-PLAN signed magic link (PortalSignedUrlService::showUrl) so the
     * email deep-links the customer straight into THEIR portal. Fall back to the
     * per-shop MailSettings.portal_store_page_url landing page when the plan cannot be
     * signed (no public_id / no resolvable customer identity), then null.
     */
    private function portalUrlFor(InstallmentPlan $plan): ?string
    {
        $signed = $this->signedPortalUrlFor($plan);
        if ($signed !== null) {
            return $signed;
        }

        $settings = MerchantMailSettings::acrossAllTenants()
            ->where('shop_id', $plan->shop_id)
            ->first();

        return $settings?->portal_store_page_url ?: null;
    }

    /**
     * The signed per-plan magic link, or null when the plan lacks the identity a
     * link must bind (public_id + a resolvable customer ref). A mail failure must
     * never abort the money pipeline, so any error here degrades to the fallback.
     */
    private function signedPortalUrlFor(InstallmentPlan $plan): ?string
    {
        if (empty($plan->public_id)) {
            return null;
        }
        if (PortalSignedUrlService::customerRef($plan)
            === PortalSignedUrlService::CUSTOMER_REF_NONE) {
            return null;
        }

        try {
            return app(PortalSignedUrlService::class)->showUrl($plan);
        } catch (\Throwable) {
            return null;
        }
    }

    private function findOrCreatePayment(InstallmentPlan $plan, PaymentType $type): InstallmentPayment
    {
        $sequence = $this->nextSequenceFor($plan, $type);

        $payment = InstallmentPayment::query()->firstOrCreate(
            [
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'sequence' => $sequence,
            ],
            [
                'payment_type' => $type->value,
                'amount' => $this->amountFor($plan, $type, $sequence),
                'currency' => $plan->currency,
            ],
        );

        // Intro-window step-up: the FIRST slot priced past the window records the
        // price rise once (Timeline + emit-once meta flag). Stamp time, not success
        // time — the slot amount is frozen here, so this IS when the price changed.
        // Asked by charge ordinal for the same reason amountFor() is.
        if ($payment->wasRecentlyCreated) {
            $this->noteIntroWindowStepUp($plan, $this->cycleAmounts->chargeNumberForNext($plan));
        }

        // status is guarded (the slot state machine owns it). A brand-new slot is
        // BORN `pending`; set it via forceFill so the in-memory instance carries
        // the value the very next transitionTo() reads.
        if ($payment->wasRecentlyCreated && ($payment->status === null || $payment->status === '')) {
            $payment->forceFill(['status' => PaymentStatus::PENDING->value])->save();
        }

        return $payment;
    }

    /**
     * WHICH SLOT this attempt belongs to.
     *
     * Derived from the count of SUCCEEDED payments, so every attempt at ONE debt
     * lands on ONE slot under ONE idempotency key: counting every row instead
     * would hand each retry a new sequence, the gateway could no longer collapse
     * two attempts at one debt, and the attempt counter would restart forever so
     * the dunning window would never close.
     *
     * But a cycle we GAVE UP ON is not the same debt as the cycle after it, and
     * the succeeded-count alone cannot tell them apart — nobody paid either, so
     * both resolve to the same number. Left there, next month reopens last
     * month's slot: it inherits a spent attempt counter (so the new cycle gets
     * one ask instead of the merchant's ten, for the rest of the subscription's
     * life) and last month's frozen price (so a price the merchant has since
     * changed is never collected).
     *
     * So a slot that has been given up on — terminally `failed`, no retry
     * scheduled — is closed, and the next cycle opens the slot after it. Only
     * for RECURRING: an installments plan owes a fixed total, its slices are
     * numbered against that total, and a slice nobody paid is still owed.
     */
    private function nextSequenceFor(InstallmentPlan $plan, PaymentType $type): int
    {
        $sequence = $plan->payments()->where('status', PaymentStatus::SUCCEEDED->value)->count() + 1;

        if ($plan->plan_kind !== PlanKind::RECURRING) {
            return $sequence;
        }

        $candidate = $plan->payments()->where('sequence', $sequence)->first();

        $abandoned = $candidate !== null
            && $candidate->status === PaymentStatus::FAILED
            && $candidate->next_retry_at === null;

        if (! $abandoned) {
            return $sequence;
        }

        // A fresh debt, after the one we stopped asking for.
        return (int) $plan->payments()->max('sequence') + 1;
    }

    private function amountFor(InstallmentPlan $plan, PaymentType $type, int $sequence): float
    {
        if ($plan->plan_kind === PlanKind::RECURRING) {
            // The shared resolver owns the recurring ladder (override → stepped-up
            // regular_amount past the intro window → installment_amount), and it is
            // asked in terms of the CHARGE ORDINAL — which is what the intro window
            // counts. Ordinarily that equals the slot sequence, checkout counted as
            // #1 (it occupies the succeeded seq-0 slot). It stops equalling it after
            // a cycle we gave up on: that cycle closes its slot and the next one
            // opens a later sequence, but nobody was charged for it, so it must not
            // burn a discounted cycle the customer never received.
            return $this->cycleAmounts->amountForCharge($plan, $this->cycleAmounts->chargeNumberForNext($plan));
        }

        // Installments: per-slot amount, capped at the remaining balance.
        $slot = (float) ($plan->installment_amount ?: $plan->remainingAmount());

        return round(min($slot, $plan->remainingAmount()), 2);
    }

    /**
     * Record the intro-window price step-up ONCE — when the first slot past the
     * window is stamped. Guarded by a meta flag so retries/replays never write a
     * second Timeline row.
     */
    private function noteIntroWindowStepUp(InstallmentPlan $plan, int $sequence): void
    {
        if ($plan->plan_kind !== PlanKind::RECURRING
            || $sequence !== ((int) ($plan->discount_cycles ?? 0)) + 1
            || ! $this->cycleAmounts->windowEndedAt($plan, $sequence)) {
            return;
        }

        $meta = (array) ($plan->meta ?? []);
        if (! empty($meta[InstallmentPlan::META_INTRO_WINDOW_ENDED])) {
            return;
        }

        $meta[InstallmentPlan::META_INTRO_WINDOW_ENDED] = now()->toIso8601String();
        $plan->forceFill(['meta' => $meta])->save();

        Timeline::record(
            kind: Timeline::KIND_PRICE_STEPPED_UP,
            details: [
                'from_amount' => round((float) $plan->installment_amount, 2),
                'to_amount' => round((float) $plan->regular_amount, 2),
                'charge_number' => $sequence,
            ],
            planId: $plan->getKey(),
            shopId: $plan->shop_id,
        );
    }

    private function idempotencyKeyFor(InstallmentPlan $plan, PaymentType $type): string
    {
        $shopId = (int) $plan->shop_id;

        if ($plan->plan_kind === PlanKind::RECURRING) {
            $cycle = ($plan->next_charge_at ? CarbonImmutable::parse($plan->next_charge_at) : CarbonImmutable::now())->format('Y-m-d');

            return IdempotencyKey::recurring($shopId, (int) $plan->getKey(), $cycle);
        }

        $sequence = $this->nextSequenceFor($plan, $type);

        return IdempotencyKey::installment($shopId, (int) $plan->getKey(), $sequence);
    }

    /**
     * Is there a stored consent for this plan's customer to charge a saved token?
     * Required before any future saved-token charge (CLAUDE.md money-safety law).
     * Matched on (shop_id, customer, consent_context). The BelongsToShop scope
     * already pins shop_id; we match the customer by internal id OR shopify id so
     * a consent captured at checkout (shopify_customer_id only) still satisfies a
     * later charge that also carries the internal customer_id.
     */
    private function hasConsent(InstallmentPlan $plan): bool
    {
        $hasCustomerId = $plan->customer_id !== null;
        $hasShopifyId = $plan->shopify_customer_id !== null && $plan->shopify_customer_id !== '';

        // Fail closed: a plan with no customer identity at all can never be
        // matched to a consent row — never let an empty match clause pass.
        if (! $hasCustomerId && ! $hasShopifyId) {
            return false;
        }

        return CustomerConsent::query()
            ->where('shop_id', (int) $plan->shop_id)
            ->where('consent_context', $this->consentContextFor($plan))
            ->where(function ($q) use ($plan, $hasCustomerId, $hasShopifyId): void {
                if ($hasCustomerId) {
                    $q->orWhere('customer_id', $plan->customer_id);
                }
                if ($hasShopifyId) {
                    $q->orWhere('shopify_customer_id', $plan->shopify_customer_id);
                }
            })
            ->exists();
    }

    /** Map plan_kind → the consent_context the customer must have accepted. */
    private function consentContextFor(InstallmentPlan $plan): string
    {
        return $plan->plan_kind === PlanKind::RECURRING
            ? CustomerConsent::CONTEXT_RECURRING
            : CustomerConsent::CONTEXT_INSTALLMENTS;
    }

    private function advanceNextChargeAt(InstallmentPlan $plan): CarbonImmutable
    {
        $base = $plan->next_charge_at ? CarbonImmutable::parse($plan->next_charge_at) : CarbonImmutable::now();

        return $this->oneCycleAfter($plan, $base);
    }

    /** One cadence step after $from — the plan's own frequency, or monthly. */
    private function oneCycleAfter(InstallmentPlan $plan, CarbonImmutable $from): CarbonImmutable
    {
        if ($plan->billing_frequency !== null) {
            return CarbonImmutable::parse(
                $plan->billing_frequency->addTo($from, (int) ($plan->interval_count ?: 1))
            );
        }

        // No cadence configured (pure installments without a fixed schedule):
        // default to monthly so the scheduler keeps moving.
        return $from->addMonthNoOverflow();
    }

    /**
     * Say, in the plan's own status, that a cycle is unpaid and we are on it.
     *
     * A plan already cancelled/completed is left alone — a charge that lands
     * late on a closed subscription must not reopen it.
     */
    private function enterDunning(InstallmentPlan $plan): void
    {
        $current = $plan->status instanceof PlanStatus
            ? $plan->status
            : PlanStatus::from((string) $plan->status);

        if (in_array($current, [PlanStatus::AWAITING_PAYMENT, PlanStatus::CANCELLED, PlanStatus::COMPLETED], true)) {
            return;
        }

        // awaiting_first_payment → awaiting_payment is a legal edge; draft is NOT,
        // and must be walked up one rung first — exactly as ensureActiveThen()
        // does on the success side. Getting this wrong does not produce a wrong
        // status: it throws IllegalTransitionException from inside the charge,
        // after the gateway has already been called.
        if ($current === PlanStatus::DRAFT) {
            $plan->transitionTo(PlanStatus::AWAITING_FIRST_PAYMENT, ['action' => 'charge_attempted']);
            $plan->transitionTo(PlanStatus::AWAITING_PAYMENT, ['action' => 'charge_failed']);

            return;
        }

        if ($current === PlanStatus::AWAITING_FIRST_PAYMENT) {
            $plan->transitionTo(PlanStatus::AWAITING_PAYMENT, ['action' => 'charge_failed']);

            return;
        }

        if ($current === PlanStatus::FAILED || $current === PlanStatus::PAUSED) {
            $plan->transitionTo(PlanStatus::ACTIVE, ['action' => 'dunning_resumed']);
        }

        $plan->transitionTo(PlanStatus::AWAITING_PAYMENT, ['action' => 'charge_failed']);
    }

    /**
     * The dunning window closed without the money arriving: give up on THIS
     * cycle and wait for the next ordinary one.
     *
     * The new date is rolled forward whole cycles until it is in the FUTURE.
     * Adding a single interval to a date already weeks past would leave the
     * plan still due, and the scheduler — which runs every five minutes —
     * would bill every missed cycle in a matter of minutes. A subscriber owes
     * the cycle they are in, never the ones they were never asked for.
     */
    private function skipToNextCycle(InstallmentPlan $plan): void
    {
        if ($plan->plan_kind !== PlanKind::RECURRING) {
            // An installments plan owes a FIXED total; a skipped attempt is not a
            // forgiven slice. It waits for the merchant (or a card update), so
            // the schedule stops here rather than inventing a new date.
            $plan->next_charge_at = null;
            $plan->save();

            return;
        }

        $skipped = 0;
        $next = $this->advanceNextChargeAt($plan);
        $now = CarbonImmutable::now();

        while ($next->lessThanOrEqualTo($now) && $skipped < self::MAX_CYCLE_ROLL_FORWARD) {
            $next = $this->oneCycleAfter($plan, $next);
            $skipped++;
        }

        $plan->next_charge_at = $next;
        $plan->save();

        Timeline::record(
            kind: Timeline::KIND_CHARGE_FAILED,
            details: [
                'action' => 'cycle_skipped',
                'skipped_cycles' => $skipped + 1,
                'next_charge_at' => $next->toIso8601String(),
            ],
            planId: $plan->getKey(),
            shopId: $plan->shop_id,
        );
    }

    /** Bring a plan to ACTIVE first if needed, then transition to the target. */
    private function ensureActiveThen(InstallmentPlan $plan, PlanStatus $target): void
    {
        $current = $plan->status instanceof PlanStatus ? $plan->status : PlanStatus::from((string) $plan->status);

        if ($current === $target) {
            return;
        }

        // draft/awaiting_first_payment/awaiting_payment must reach active first.
        if (in_array($current, [PlanStatus::DRAFT, PlanStatus::AWAITING_FIRST_PAYMENT, PlanStatus::AWAITING_PAYMENT], true)) {
            if ($current === PlanStatus::DRAFT) {
                $plan->transitionTo(PlanStatus::AWAITING_FIRST_PAYMENT);
            }
            $plan->transitionTo(PlanStatus::ACTIVE);
        }

        $plan->transitionTo($target);
    }

    private function maybeIssueDocument(
        InstallmentPlan $plan,
        PaymentType $type,
        bool $isFinal,
        PaymentLedger $ledger,
    ): void {
        // Derive the DOCUMENT context ONCE and ask the policy about that, rather than
        // deriving finality here and again in the dispatch below. PaymentType can only
        // yield deposit/installment/recurring today, so this is not a live bug — but
        // ChargeContext also carries `retry` and `manual`, which the policy has no arm
        // for and would silently answer `none()` to. Should a retry/manual payment
        // type ever be added, documentContextFor() already resolves it to the plan's
        // real kind, and routing the policy through it keeps the two from drifting.
        $documentContext = $this->documentContextFor($type, $isFinal);

        $decision = $this->documentPolicy->decide(new DocumentPolicyInput(
            shop: $plan->shop,
            chargeContext: $documentContext->value,
            planKind: $plan->plan_kind->value,
            amount: (float) $ledger->amount,
            isFinalPayment: $documentContext === DocumentContext::FINAL_INSTALLMENT,
            merchantSettings: (array) (($plan->meta ?? [])['document_settings'] ?? []),
        ));

        if (! $decision->shouldIssueNow || $decision->documentType === null) {
            return;
        }

        Timeline::record(
            kind: 'document_issue_requested',
            details: ['document_type' => $decision->documentType],
            planId: $plan->getKey(),
            shopId: $plan->shop_id,
        );

        // Hand off to the invoicing module. IssueDocumentJob::queueAfterCommit() is afterCommit
        // + fail-soft, deliberately: we are inside charge()'s DB::transaction, so no
        // HTTP may happen here, and a slow, dead, or unreachable invoicing path must
        // never hold a row lock, delay a charge, or roll back money that already
        // moved. The job is idempotent on the ledger row's own key.
        IssueDocumentJob::queueAfterCommit(
            shopId: (int) $plan->shop_id,
            context: $documentContext->value,
            ledgerId: (int) $ledger->getKey(),
        );
    }

    /**
     * The invoicing context for a charge. Mirrors DefaultDocumentPolicy's own
     * normalisation: a FINAL installment is its own context even though it arrives
     * as charge_context = installment. retry/manual re-enter the plan's real kind —
     * an invoicing provider must never be told "retry" as if it were a sale type.
     */
    private function documentContextFor(PaymentType $type, bool $isFinal): DocumentContext
    {
        $context = $type->toChargeContext();

        return match ($context) {
            ChargeContext::DEPOSIT => DocumentContext::DEPOSIT,
            ChargeContext::INSTALLMENT => $isFinal
                ? DocumentContext::FINAL_INSTALLMENT
                : DocumentContext::INSTALLMENT,
            ChargeContext::RECURRING => DocumentContext::RECURRING,
            ChargeContext::UPSELL => DocumentContext::UPSELL,
            ChargeContext::RETRY, ChargeContext::MANUAL => $isFinal
                ? DocumentContext::FINAL_INSTALLMENT
                : DocumentContext::INSTALLMENT,
        };
    }
}
