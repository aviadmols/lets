<?php

namespace App\Domain\Installments;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Installments\Contracts\DepositTokenResolver;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Activates a DEPOSIT + installments plan once its deposit is PAID (W9 Part C).
 *
 * The deposit was paid by the customer on PayPlus' hosted invoice page (the
 * orders/paid webhook fires for that order). The money already moved — so here we:
 *   1. find the plan from the order's draft id / note attribute (tenant-scoped),
 *   2. capture the reusable PayPlus token (via the optional DepositTokenResolver
 *      seam to laravel-backend) and vault it as the plan's payment method,
 *   3. record the deposit as a SUCCEEDED ledger row + payment slot (idempotent on
 *      the deposit key — a twice-delivered webhook activates exactly once),
 *   4. record the customer's consent to future installment charges,
 *   5. transition the plan awaiting_first_payment → active and set next_charge_at
 *      to the first scheduled slice, so DispatchDuePlansCommand / ChargeOrchestrator
 *      take over the remaining installments.
 *
 * Tenant law: the caller (OrderPaidHandler) has bound the tenant from the webhook's
 * shop; every lookup here is BelongsToShop-scoped, and we assert $shop matches.
 * Money law: no second charge — the deposit charge already happened on PayPlus; we
 * only RECORD it. The recurring slices go through the engine's consent + method
 * checks, never bypassing them.
 */
final class PlanActivationService
{
    // === CONSTANTS ===
    /** The custom-attribute key the deposit draft/order carries (links to the plan). */
    private const ATTR_PLAN_PUBLIC_ID = 'pps_plan_public_id';

    /**
     * The token resolver is OPTIONAL: laravel-backend binds the real
     * PayPlusCustomerTokenResolver; until then activation still records the paid
     * deposit + advances the schedule (the later charges are gated by the engine's
     * own consent/payment-method checks, so nothing charges without a token).
     */
    public function __construct(private readonly ?DepositTokenResolver $tokenResolver = null) {}

    /**
     * Activate the deposit plan referenced by a paid order, if any. Returns the
     * activated plan, or null when this order is not a LETS deposit (the common
     * case — most orders/paid are ordinary orders).
     *
     * @param  array<string, mixed>  $orderPayload  the orders/paid webhook body
     */
    public function activateFromPaidOrder(Shop $shop, array $orderPayload): ?InstallmentPlan
    {
        $plan = $this->resolvePlan($shop, $orderPayload);
        if ($plan === null) {
            return null; // not a deposit order we own — nothing to do
        }

        // Already active/terminal ⇒ a replayed webhook; no-op (idempotent).
        if (! in_array($plan->status, [PlanStatus::DRAFT, PlanStatus::AWAITING_FIRST_PAYMENT], true)) {
            return $plan;
        }

        $orderId = (string) (data_get($orderPayload, 'id') ?? '');
        $depositAmount = $this->depositAmountFor($plan, $orderPayload);

        return DB::transaction(function () use ($shop, $plan, $orderId, $orderPayload, $depositAmount): InstallmentPlan {
            // Re-read under a row lock so two simultaneous deliveries serialise.
            /** @var InstallmentPlan $plan */
            $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! in_array($plan->status, [PlanStatus::DRAFT, PlanStatus::AWAITING_FIRST_PAYMENT], true)) {
                return $plan; // won the race already
            }

            // 1) Capture + vault the reusable token (when a resolver is bound).
            $method = $this->capturePaymentMethod($shop, $plan, $orderPayload);
            if ($method !== null) {
                $plan->payment_method_id = $method->getKey();
            }

            // 2) Record the deposit as a SUCCEEDED ledger row (idempotent on the key).
            // The checkout ref is plan-scoped + stable so a replayed webhook reuses
            // the same key and never records a second deposit.
            $key = IdempotencyKey::deposit((int) $shop->getKey(), 'plan:'.(string) $plan->public_id);
            if (! Ledger::hasSucceeded((int) $shop->getKey(), $key)) {
                $ledger = Ledger::open(
                    shopId: (int) $shop->getKey(),
                    chargeContext: 'deposit',
                    idempotencyKey: $key,
                    amount: $depositAmount,
                    currency: (string) $plan->currency,
                    attributes: [
                        'plan_id' => $plan->getKey(),
                        'shopify_order_id' => $orderId,
                        'shopify_customer_id' => $plan->shopify_customer_id,
                    ],
                );
                Ledger::transition($ledger, LedgerStatus::SUCCEEDED, [
                    'shopify_order_id' => $orderId,
                ]);
            }

            // 3) Record the deposit payment SLOT (sequence 0 = the deposit).
            $this->recordDepositPaymentSlot($plan, $depositAmount);

            // 4) Advance the plan's money + state.
            $plan->total_charged = round((float) $plan->total_charged + $depositAmount, 2);
            $plan->shopify_order_id = $orderId !== '' ? $orderId : $plan->shopify_order_id;
            $plan->next_charge_at = $this->firstInstallmentDueAt($plan);
            $plan->save();

            // 5) Record consent to future installment charges (captured at deposit pay).
            $this->recordConsent($shop, $plan);

            // awaiting_first_payment → active.
            if ($plan->status !== PlanStatus::ACTIVE) {
                $plan->transitionTo(PlanStatus::ACTIVE);
            }

            Timeline::record(
                kind: 'deposit_paid_plan_activated',
                details: [
                    'plan_public_id' => $plan->public_id,
                    'shopify_order_id' => $orderId,
                    'deposit_amount' => $depositAmount,
                    'next_charge_at' => $plan->next_charge_at?->toIso8601String(),
                    'token_captured' => $method !== null,
                ],
                planId: $plan->getKey(),
                shopId: (int) $shop->getKey(),
            );

            return $plan;
        });
    }

    /**
     * Find the plan this paid order activates: prefer the plan's stored draft id
     * (DepositPlanService wrote the draft's legacyResourceId into shopify_order_id),
     * then fall back to the order's pps_plan_public_id note/custom attribute.
     *
     * @param  array<string, mixed>  $orderPayload
     */
    private function resolvePlan(Shop $shop, array $orderPayload): ?InstallmentPlan
    {
        // Strongest signal: the order carries our plan public id (copied from the
        // deposit draft's customAttributes onto the order's note_attributes).
        $publicId = $this->attribute($orderPayload, self::ATTR_PLAN_PUBLIC_ID);
        if ($publicId !== null && $publicId !== '') {
            $plan = InstallmentPlan::query()->where('public_id', $publicId)->first();
            if ($plan !== null) {
                return $plan;
            }
        }

        // Fallback: the draft that became this order. DepositPlanService stored the
        // draft's legacyResourceId in the plan meta; orders/paid carries the source
        // draft id in `draft_order_id`.
        $draftId = (string) (data_get($orderPayload, 'draft_order_id') ?? '');
        if ($draftId !== '') {
            $plan = InstallmentPlan::query()
                ->where('meta->'.DepositPlanService::META_DRAFT_ID, $draftId)
                ->first();
            if ($plan !== null) {
                return $plan;
            }
        }

        return null; // not a LETS deposit order we own
    }

    /** Vault the captured token as the plan's payment method, or null when none. */
    private function capturePaymentMethod(Shop $shop, InstallmentPlan $plan, array $orderPayload): ?InstallmentPaymentMethod
    {
        if ($this->tokenResolver === null) {
            return null;
        }

        try {
            $token = $this->tokenResolver->resolveFromOrder($shop, $orderPayload);
        } catch (\Throwable $e) {
            Log::warning('installments.deposit.token_capture_failed', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($token === null || ($token['payplus_card_token_uid'] ?? null) === null) {
            return null;
        }

        return InstallmentPaymentMethod::query()->create([
            'customer_id' => $plan->customer_id,
            'shopify_customer_id' => $plan->shopify_customer_id,
            'payplus_card_token_uid' => $token['payplus_card_token_uid'] ?? null,
            'payplus_customer_uid' => $token['payplus_customer_uid'] ?? null,
            'payplus_token_reference' => $token['payplus_token_reference'] ?? null,
            'card_brand' => $token['card_brand'] ?? null,
            'card_last_four' => $token['card_last_four'] ?? null,
            'exp_month' => $token['exp_month'] ?? null,
            'exp_year' => $token['exp_year'] ?? null,
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);
    }

    /** The deposit payment slot (sequence 0), idempotent on (plan, sequence). */
    private function recordDepositPaymentSlot(InstallmentPlan $plan, float $amount): void
    {
        $payment = InstallmentPayment::query()->firstOrCreate(
            [
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'sequence' => 0,
            ],
            [
                'payment_type' => PaymentType::DEPOSIT->value,
                'amount' => round($amount, 2),
                'currency' => $plan->currency,
            ],
        );

        if ($payment->wasRecentlyCreated && ($payment->status === null || $payment->status === '')) {
            $payment->forceFill(['status' => PaymentStatus::PENDING->value])->save();
        }

        if ($payment->status !== PaymentStatus::SUCCEEDED) {
            $payment->markSucceeded(null, null, []);
        }
    }

    /** Record the consent the customer gave when starting the deposit plan. */
    private function recordConsent(Shop $shop, InstallmentPlan $plan): void
    {
        CustomerConsent::query()->firstOrCreate(
            [
                'shop_id' => (int) $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'consent_context' => CustomerConsent::CONTEXT_INSTALLMENTS,
            ],
            [
                'customer_id' => $plan->customer_id,
                'shopify_customer_id' => $plan->shopify_customer_id,
                'customer_email' => $plan->customer_email,
                'accepted_at' => now(),
                'billing_amount_description' => sprintf(
                    '%s × installments after a %s deposit',
                    (string) $plan->installment_amount,
                    (string) (data_get($plan->meta, DepositPlanService::META_DEPOSIT_AMOUNT) ?? ''),
                ),
                'billing_frequency_description' => (string) ($plan->billing_frequency?->value ?? ''),
            ],
        );
    }

    /** The first scheduled installment date from the stored quote, or +1 cycle. */
    private function firstInstallmentDueAt(InstallmentPlan $plan): CarbonImmutable
    {
        $first = data_get($plan->meta, DepositPlanService::META_QUOTE.'.schedule.0.due_at');

        if (is_string($first) && $first !== '') {
            return CarbonImmutable::parse($first)->startOfDay();
        }

        // No stored schedule (defensive): advance one cycle from now.
        $base = CarbonImmutable::now();

        return $plan->billing_frequency !== null
            ? CarbonImmutable::parse($plan->billing_frequency->addTo($base, (int) ($plan->interval_count ?: 1)))
            : $base->addMonthNoOverflow();
    }

    /** The amount the deposit charged: prefer the order total, fall back to the quote. */
    private function depositAmountFor(InstallmentPlan $plan, array $orderPayload): float
    {
        $orderTotal = data_get($orderPayload, 'total_price');
        if (is_numeric($orderTotal) && (float) $orderTotal > 0) {
            return round((float) $orderTotal, 2);
        }

        return round((float) (data_get($plan->meta, DepositPlanService::META_DEPOSIT_AMOUNT) ?? 0), 2);
    }

    /**
     * Read a note/custom attribute by name from the order payload. Shopify exposes
     * draft customAttributes on the resulting order as `note_attributes`
     * [{name, value}].
     */
    private function attribute(array $orderPayload, string $name): ?string
    {
        foreach ((array) data_get($orderPayload, 'note_attributes', []) as $attr) {
            if (($attr['name'] ?? null) === $name) {
                return (string) ($attr['value'] ?? '');
            }
        }

        return null;
    }
}
