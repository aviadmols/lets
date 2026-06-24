<?php

namespace App\Modules\PayPlusShopifyInstallments\Services;

use App\Domain\Billing\Contracts\DocumentPolicy;
use App\Domain\Billing\Contracts\DocumentPolicyInput;
use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Events\ChargeFailed;
use App\Events\ChargeSucceeded;
use App\Mail\ManualRecurringPaymentMail;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
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
 *   1. lockForUpdate the plan inside a DB transaction (double-charge wall).
 *   2. Ledger pre-check: a succeeded row for the key short-circuits (no PayPlus call).
 *   3. Open a `pending` ledger row BEFORE the gateway call (reconcilable on death).
 *   4. Charge via PayPlusGatewayFactory::for($plan->shop) — per-shop creds, never config().
 *   5. On success: advance plan state INSIDE the txn (before side effects), then docs.
 *   6. On failure: schedule retry by backoff [4h,24h,72h] or fail terminally.
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
    /** Max charge attempts before a terminal `failed`. Mirrors retry_backoff_hours length. */
    private const MAX_ATTEMPTS_FALLBACK = 3;

    /**
     * The Shopify order strategy is OPTIONAL + nullable so the billing engine
     * stays decoupled from the Shopify boundary (and unit tests can run the money
     * pipeline without any Shopify wiring). When bound (by the shopify-integration
     * service provider), onSuccess() materializes Shopify state AFTER the ledger
     * is succeeded — a Shopify hiccup never rolls back the money truth.
     */
    public function __construct(
        private readonly DocumentPolicy $documentPolicy,
        private readonly ?ShopifyOrderStrategy $shopifyOrders = null,
    ) {}

    /**
     * Charge one plan for a given payment type. Tenant must already be bound by
     * the job middleware; $plan is resolved under that scope.
     */
    public function charge(int $planId, PaymentType $type): ChargeOutcome
    {
        return DB::transaction(function () use ($planId, $type): ChargeOutcome {
            // Row lock: two simultaneous triggers serialise here. BelongsToShop
            // scopes the lookup to the bound tenant.
            $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($planId);
            $shopId = (int) $plan->shop_id;

            $key = $this->idempotencyKeyFor($plan, $type);

            // Idempotent short-circuit — a succeeded ledger row means done.
            if (Ledger::hasSucceeded($shopId, $key)) {
                return ChargeOutcome::skipped('already_succeeded', $key);
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

            // Ledger opens PENDING before the side effect.
            $ledger = Ledger::open(
                shopId: $shopId,
                chargeContext: $type->toChargeContext()->value,
                idempotencyKey: $key,
                amount: $amount,
                currency: (string) ($plan->currency ?? config('payplus.currency', 'ILS')),
                attributes: [
                    'plan_id' => $plan->getKey(),
                    'payment_method_id' => $plan->payment_method_id,
                    'customer_id' => $plan->customer_id,
                    'shopify_customer_id' => $plan->shopify_customer_id,
                    'shopify_order_id' => $plan->shopify_order_id,
                ],
            );

            $gateway = PayPlusGatewayFactory::for($plan->shop);
            $result = $gateway->chargeWithReference(
                $plan->activePaymentMethod(),
                $amount,
                $key,
                ['currency' => $plan->currency],
            );

            $plan->forceFill(['last_charge_attempt_at' => now()])->save();

            return $result->success
                ? $this->onSuccess($plan, $payment, $ledger, $result, $type)
                : $this->onFailure($plan, $payment, $ledger, $result);
        });
    }

    // === Success / failure branches ===

    private function onSuccess(
        InstallmentPlan $plan,
        InstallmentPayment $payment,
        PaymentLedger $ledger,
        GatewayResult $result,
        PaymentType $type,
    ): ChargeOutcome {
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
        } else {
            // Installments, not final — schedule the next slot + update parent.
            $plan->next_charge_at = $this->advanceNextChargeAt($plan);
            $plan->save();
        }

        // Documents — ONLY via the policy. The orchestrator never names a type.
        $this->maybeIssueDocument($plan, $type, $isFinal, $ledger);

        // Materialize Shopify state — AFTER the ledger is succeeded + the plan
        // advanced. Owned by shopify-integration's ShopifyOrderStrategy; the
        // orchestrator only knows the interface. Installments-final releases
        // fulfillment; recurring creates a new fulfillable order; deposit/first
        // installment update the parent. A Shopify failure is logged, never
        // unwound (the money already moved and is recorded in the ledger).
        $this->materializePlatformOrder($plan, $type->toChargeContext(), $isFinal);

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

        // Notification — fired AFTER the ledger row + Timeline are written (money
        // truth first; an email is never the reason a charge "happened"). The
        // listener is tenant-bound + wraps the send in try/catch so a mail failure
        // can never roll back or block the charge.
        ChargeSucceeded::dispatch(
            (int) $plan->shop_id,
            $plan,
            $payment,
            $isFirstPayment,
            $isFinal,
        );

        return ChargeOutcome::succeeded($ledger->idempotency_key, $result->transactionUid, $isFinal);
    }

    private function onFailure(
        InstallmentPlan $plan,
        InstallmentPayment $payment,
        PaymentLedger $ledger,
        GatewayResult $result,
    ): ChargeOutcome {
        $payment->attempt_count = (int) $payment->attempt_count + 1;
        $payment->failure_code = $result->errorCode;
        $payment->failure_message = $result->errorMessage;

        $backoff = (array) config('payplus.retry_backoff_hours', [4, 24, 72]);
        $maxAttempts = count($backoff) ?: self::MAX_ATTEMPTS_FALLBACK;
        $willRetry = $payment->attempt_count < $maxAttempts;

        if ($willRetry) {
            // attempt_count is 1-based after increment; index the NEXT backoff.
            $hours = $backoff[min($payment->attempt_count, $maxAttempts) - 1] ?? end($backoff);
            $payment->next_retry_at = now()->addHours((int) $hours);
            $payment->save();
            $payment->transitionTo(PaymentStatus::RETRY_SCHEDULED);

            Ledger::transition($ledger, LedgerStatus::FAILED, [
                'failure_code' => $result->errorCode,
                'failure_message' => $result->errorMessage,
                'raw_response_masked' => ResponseMasker::mask($result->raw),
            ]);
            Ledger::transition($ledger, LedgerStatus::RETRY_SCHEDULED);

            // Plan goes failed only after retries are scheduled? Keep plan active
            // while retries pending; flip to failed only on terminal exhaustion.
        } else {
            $payment->next_retry_at = null;
            $payment->save();
            $payment->transitionTo(PaymentStatus::FAILED);

            Ledger::transition($ledger, LedgerStatus::FAILED, [
                'failure_code' => $result->errorCode,
                'failure_message' => $result->errorMessage,
                'raw_response_masked' => ResponseMasker::mask($result->raw),
            ]);

            if (! in_array($plan->status, [PlanStatus::FAILED, PlanStatus::CANCELLED, PlanStatus::COMPLETED], true)) {
                $this->ensureActiveThen($plan, PlanStatus::FAILED);
            }
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

        // Notification — fired AFTER the ledger row + Timeline are written. The
        // failed-charge email tells the customer the reason + the next retry date.
        ChargeFailed::dispatch(
            (int) $plan->shop_id,
            $plan,
            $payment,
            $result->errorCode,
            $result->errorMessage,
            $willRetry,
        );

        return ChargeOutcome::failed($ledger->idempotency_key, $result->errorCode, $willRetry);
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
        $recipient = $this->recipientFor($plan);

        // The email is a side effect — wrap it so a mail failure never aborts the
        // money pipeline (the marker is already set, the clock already advanced).
        if ($recipient !== '') {
            try {
                Mail::to($recipient)->send(
                    new ManualRecurringPaymentMail(
                        shop: $plan->shop,
                        plan: $plan,
                        portalUrl: $this->portalUrlFor($plan),
                        invoiceUrl: $invoiceUrl,
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

        Timeline::record(
            kind: 'manual_payment_email_sent',
            details: ['type' => $type->value, 'key' => $key, 'invoice_url' => $invoiceUrl],
            planId: $plan->getKey(),
            shopId: $plan->shop_id,
        );

        return ChargeOutcome::skipped('manual_mode', $key);
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
     * The signed customer-portal URL for the plan, when one can be built. The
     * SignedUrlService magic-link port lands in Phase 6.5; until then we read an
     * explicit per-shop portal landing URL from MailSettings if configured.
     * TODO(phase 6.5): SignedUrlService::portalShowUrl($plan).
     */
    private function portalUrlFor(InstallmentPlan $plan): ?string
    {
        $settings = \App\Models\MerchantMailSettings::acrossAllTenants()
            ->where('shop_id', $plan->shop_id)
            ->first();

        return $settings?->portal_store_page_url ?: null;
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
                'amount' => $this->amountFor($plan, $type),
                'currency' => $plan->currency,
            ],
        );

        // status is guarded (the slot state machine owns it). A brand-new slot is
        // BORN `pending`; set it via forceFill so the in-memory instance carries
        // the value the very next transitionTo() reads.
        if ($payment->wasRecentlyCreated && ($payment->status === null || $payment->status === '')) {
            $payment->forceFill(['status' => PaymentStatus::PENDING->value])->save();
        }

        return $payment;
    }

    private function nextSequenceFor(InstallmentPlan $plan, PaymentType $type): int
    {
        // Recurring: the cycle index advances every charge. Installments: the
        // next unpaid slot. We derive from the count of existing payments so a
        // re-run of the same due cycle reuses the same sequence (idempotent).
        if ($plan->plan_kind === PlanKind::RECURRING) {
            $succeeded = $plan->payments()->where('status', PaymentStatus::SUCCEEDED->value)->count();

            return $succeeded + 1;
        }

        $existing = $plan->payments()->count();

        return max(1, $existing + 1);
    }

    private function amountFor(InstallmentPlan $plan, PaymentType $type): float
    {
        if ($plan->plan_kind === PlanKind::RECURRING) {
            return round((float) $plan->installment_amount, 2);
        }

        // Installments: per-slot amount, capped at the remaining balance.
        $slot = (float) ($plan->installment_amount ?: $plan->remainingAmount());

        return round(min($slot, $plan->remainingAmount()), 2);
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

        if ($plan->billing_frequency !== null) {
            return CarbonImmutable::parse(
                $plan->billing_frequency->addTo($base, (int) ($plan->interval_count ?: 1))
            );
        }

        // No cadence configured (pure installments without a fixed schedule):
        // default to monthly so the scheduler keeps moving.
        return $base->addMonthNoOverflow();
    }

    /** Bring a plan to ACTIVE first if needed, then transition to the target. */
    private function ensureActiveThen(InstallmentPlan $plan, PlanStatus $target): void
    {
        $current = $plan->status instanceof PlanStatus ? $plan->status : PlanStatus::from((string) $plan->status);

        if ($current === $target) {
            return;
        }

        // draft/awaiting_first_payment must reach active before completed/failed.
        if (in_array($current, [PlanStatus::DRAFT, PlanStatus::AWAITING_FIRST_PAYMENT], true)) {
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
        $decision = $this->documentPolicy->decide(new DocumentPolicyInput(
            shop: $plan->shop,
            chargeContext: $type->toChargeContext()->value,
            planKind: $plan->plan_kind->value,
            amount: (float) $ledger->amount,
            isFinalPayment: $isFinal,
            merchantSettings: (array) (($plan->meta ?? [])['document_settings'] ?? []),
        ));

        if (! $decision->shouldIssueNow || $decision->documentType === null) {
            return;
        }

        // TODO(phase 3.x): call the gateway books endpoint with $decision->documentType,
        // then persist payplus_document_uid back onto $ledger. The decision is the
        // contract; the gateway call is a thin parameterised executor.
        Timeline::record(
            kind: 'document_issue_requested',
            details: ['document_type' => $decision->documentType],
            planId: $plan->getKey(),
            shopId: $plan->shop_id,
        );
    }
}
