<?php

namespace App\Domain\Billing;

use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\PlatformContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHAT the customer agreed to be charged — and the wall that holds a saved-card
 * charge to it.
 *
 * Consent used to be a yes/no per CUSTOMER: once someone agreed to one plan,
 * every later plan for them passed the gate, at any amount and any cadence the
 * merchant set (a price edit, a next-order override, a bulk yearly → monthly
 * switch). Now:
 *
 *  1. BOUND TO THE PLAN. The consent that counts is the latest row for THIS plan.
 *     A row bound to another plan never transfers. A legacy row written before
 *     rows carried a plan (plan_id NULL) still counts for its customer — every
 *     writer today binds, so only history has them — and is bound to the plan the
 *     first time it is charged (a copied row, ceiling_source = baseline).
 *  2. A CEILING. Every row carries the terms agreed: the per-cycle amount (or the
 *     plan total for installments) and the cadence. A charge above the ceiling
 *     by more than the tolerance, or a cadence tighter than agreed, is REFUSED
 *     before any ledger row or gateway call — logged, and written to the plan's
 *     Timeline where the merchant sees it (deduplicated per day, the scheduler
 *     re-asks every few minutes).
 *  3. RAISED ONLY BY CONSENT. A new customer consent (checkout, account offer),
 *     a next-order change the CUSTOMER made in their account area (that exact
 *     amount, that one cycle), or an explicit, logged merchant approval
 *     (approveAboveConsent: a new plan-bound row naming who approved and why).
 *
 * A row with no ceiling yet (written before this existed) takes the plan's
 * current terms as its baseline the first time it is charged — the status quo
 * is blessed once, and every change after it needs one of the three above.
 */
final class ConsentCeiling
{
    // === CONSTANTS ===
    /** A charge may exceed the consented amount by this share (rounding, a VAT step)… */
    public const TOLERANCE_RATIO = 0.05;

    /** …or by this absolute amount, whichever is larger. */
    public const TOLERANCE_ABSOLUTE = 1.00;

    public const SOURCE_CUSTOMER = 'customer';

    public const SOURCE_MIGRATED = 'migrated';

    public const SOURCE_BASELINE = 'baseline';

    public const SOURCE_MERCHANT_OVERRIDE = 'merchant_override';

    public const REASON_AMOUNT = 'amount_above_consent';

    public const REASON_CADENCE = 'cadence_tighter_than_consent';

    /** A refusal is written to the Timeline at most once per plan per this window. */
    private const TIMELINE_DEDUPE_HOURS = 24;

    /** Approximate cycle length in days, for comparing cadences only. */
    private const CYCLE_DAYS = [
        'daily' => 1,
        'weekly' => 7,
        'biweekly' => 14,
        'monthly' => 30,
        'quarterly' => 91,
        'yearly' => 365,
    ];

    /**
     * The terms a consent row records for $plan as it stands — merged into every
     * consent writer's create payload.
     *
     * @return array{consented_amount: float, consented_frequency: string|null, consented_interval: int|null, ceiling_source: string}
     */
    public static function termsFor(InstallmentPlan $plan, string $source = self::SOURCE_CUSTOMER): array
    {
        $recurring = $plan->plan_kind === PlanKind::RECURRING;

        return [
            'consented_amount' => $recurring
                ? round(max((float) $plan->installment_amount, (float) ($plan->regular_amount ?? 0)), 2)
                : round((float) $plan->total_amount, 2),
            'consented_frequency' => $recurring ? ($plan->billing_frequency?->value) : null,
            'consented_interval' => $recurring ? max(1, (int) ($plan->interval_count ?: 1)) : null,
            'ceiling_source' => $source,
        ];
    }

    /** The consent_context a plan's saved-token charges need. */
    public static function contextFor(InstallmentPlan $plan): string
    {
        return $plan->plan_kind === PlanKind::RECURRING
            ? CustomerConsent::CONTEXT_RECURRING
            : CustomerConsent::CONTEXT_INSTALLMENTS;
    }

    /**
     * What the NEXT charge on $plan would be, for screens (the orchestrator reads
     * its own open slot). Recurring: the shared cycle ladder; installments: the
     * next slot, capped at the remaining balance.
     */
    public static function nextAmount(InstallmentPlan $plan): float
    {
        if ($plan->plan_kind === PlanKind::RECURRING) {
            $resolver = new CycleAmountResolver;

            return round($resolver->amountForCharge($plan, $resolver->chargeNumberForNext($plan)), 2);
        }

        return round(min((float) ($plan->installment_amount ?: $plan->remainingAmount()), $plan->remainingAmount()), 2);
    }

    /**
     * The consent that authorises charging $plan, bound to it — or null (the
     * charge must not run). Matches the context the plan kind needs.
     */
    public function consentFor(InstallmentPlan $plan, string $context): ?CustomerConsent
    {
        $bound = CustomerConsent::query()
            ->where('shop_id', (int) $plan->shop_id)
            ->where('consent_context', $context)
            ->where('plan_id', $plan->getKey())
            ->orderByDesc('id')
            ->first();

        if ($bound instanceof CustomerConsent) {
            if ($bound->consented_amount === null) {
                $bound->forceFill(self::termsFor($plan, self::SOURCE_BASELINE))->save();
            }

            return $bound;
        }

        $legacy = $this->legacyConsent($plan, $context);
        if ($legacy === null) {
            return null;
        }

        // Bind the customer-wide legacy consent to this plan, at today's terms.
        return CustomerConsent::query()->create([
            'shop_id' => (int) $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'consent_context' => $context,
            'customer_id' => $legacy->customer_id,
            'shopify_customer_id' => $legacy->shopify_customer_id,
            'customer_email' => $legacy->customer_email,
            'customer_ip' => $legacy->customer_ip,
            'user_agent' => $legacy->user_agent,
            'accepted_at' => $legacy->accepted_at,
            'accepted_terms_version' => $legacy->accepted_terms_version,
            'cancellation_policy_snapshot' => $legacy->cancellation_policy_snapshot,
            'billing_amount_description' => $legacy->billing_amount_description,
            'billing_frequency_description' => $legacy->billing_frequency_description,
        ] + self::termsFor($plan, self::SOURCE_BASELINE));
    }

    /**
     * Why charging $amount on $plan now would exceed what $consent covers, or null
     * when it does not.
     *
     * @return array{reason: string, amount: float, ceiling: float, allowed: float, consented_frequency?: string|null, consented_interval?: int|null}|null
     */
    public function refusal(InstallmentPlan $plan, CustomerConsent $consent, float $amount): ?array
    {
        $ceiling = round((float) $consent->consented_amount, 2);
        $allowed = self::allowedFor($ceiling);

        if ($plan->plan_kind === PlanKind::RECURRING) {
            if ($amount > $allowed && ! $this->customerAuthoredCovers($plan, $amount)) {
                return ['reason' => self::REASON_AMOUNT, 'amount' => round($amount, 2), 'ceiling' => $ceiling, 'allowed' => $allowed];
            }

            if ($this->cadenceTightened($plan, $consent)) {
                return [
                    'reason' => self::REASON_CADENCE,
                    'amount' => round($amount, 2),
                    'ceiling' => $ceiling,
                    'allowed' => $allowed,
                    'consented_frequency' => $consent->consented_frequency,
                    'consented_interval' => $consent->consented_interval,
                ];
            }

            return null;
        }

        // Installments: the plan's TOTAL is what was agreed; each slot is already
        // capped at the remaining balance, so the total is the one number to hold.
        $total = round((float) $plan->total_amount, 2);
        if ($total > $allowed) {
            return ['reason' => self::REASON_AMOUNT, 'amount' => $total, 'ceiling' => $ceiling, 'allowed' => $allowed];
        }

        return null;
    }

    /** Refuse: log every time, write the Timeline once per window so a merchant sees it without a flood. */
    public function recordRefusal(InstallmentPlan $plan, array $refusal, string $key, string $type): void
    {
        Log::warning('billing.charge_above_consent', [
            'shop_id' => (int) $plan->shop_id,
            'plan_id' => (int) $plan->getKey(),
            'key' => $key,
        ] + $refusal);

        $recent = ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', Timeline::KIND_CHARGE_ABOVE_CONSENT)
            ->where('created_at', '>=', now()->subHours(self::TIMELINE_DEDUPE_HOURS))
            ->latest('id')
            ->first();

        if ($recent !== null
            && data_get($recent->details, 'reason') === $refusal['reason']
            && (float) data_get($recent->details, 'amount') === (float) $refusal['amount']) {
            return;
        }

        Timeline::record(
            kind: Timeline::KIND_CHARGE_ABOVE_CONSENT,
            details: $refusal + ['type' => $type, 'key' => $key],
            planId: $plan->getKey(),
            shopId: (int) $plan->shop_id,
        );
    }

    /**
     * A merchant explicitly approves charging $plan at its CURRENT terms (and the
     * next order as it stands) although they exceed what the customer agreed to.
     * Appends a plan-bound row that names who approved and why; the customer's
     * own consent row is never edited.
     */
    public function approveAboveConsent(InstallmentPlan $plan, CustomerConsent $current, float $nextAmount, string $reason): CustomerConsent
    {
        $terms = self::termsFor($plan, self::SOURCE_MERCHANT_OVERRIDE);
        if ($plan->plan_kind === PlanKind::RECURRING) {
            $terms['consented_amount'] = round(max($terms['consented_amount'], $nextAmount), 2);
        }

        $actor = PlatformContext::actingActor() ?? ActivityEvent::ACTOR_SYSTEM;

        return DB::transaction(function () use ($plan, $current, $terms, $reason, $actor): CustomerConsent {
            $row = CustomerConsent::query()->create([
                'shop_id' => (int) $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'consent_context' => $current->consent_context,
                'customer_id' => $current->customer_id,
                'shopify_customer_id' => $current->shopify_customer_id,
                'customer_email' => $current->customer_email,
                'accepted_at' => $current->accepted_at,
                'accepted_terms_version' => $current->accepted_terms_version,
                'cancellation_policy_snapshot' => $current->cancellation_policy_snapshot,
                'billing_amount_description' => $current->billing_amount_description,
                'billing_frequency_description' => $current->billing_frequency_description,
                'approved_by' => $actor,
                'approval_reason' => mb_substr(trim($reason), 0, 1000),
            ] + $terms);

            Timeline::record(
                kind: Timeline::KIND_CONSENT_OVERRIDE_APPROVED,
                details: [
                    'from' => round((float) $current->consented_amount, 2),
                    'to' => $terms['consented_amount'],
                    'frequency' => $terms['consented_frequency'],
                    'interval' => $terms['consented_interval'],
                    'reason' => mb_substr(trim($reason), 0, 1000),
                ],
                planId: $plan->getKey(),
                actor: $actor,
                shopId: (int) $plan->shop_id,
            );

            Log::info('billing.consent_override_approved', [
                'shop_id' => (int) $plan->shop_id,
                'plan_id' => (int) $plan->getKey(),
                'actor' => $actor,
                'to' => $terms['consented_amount'],
            ]);

            return $row;
        });
    }

    public static function allowedFor(float $ceiling): float
    {
        return round($ceiling + max($ceiling * self::TOLERANCE_RATIO, self::TOLERANCE_ABSOLUTE), 2);
    }

    /** The customer set this next order themselves (account area) — that amount, that cycle, is theirs. */
    private function customerAuthoredCovers(InstallmentPlan $plan, float $amount): bool
    {
        $override = $plan->nextOrderOverride();

        return $override !== null
            && ($override['set_by'] ?? null) === ActivityEvent::ACTOR_CUSTOMER
            && round((float) ($override['amount'] ?? -1), 2) === round($amount, 2);
    }

    private function cadenceTightened(InstallmentPlan $plan, CustomerConsent $consent): bool
    {
        $agreed = self::cycleDays($consent->consented_frequency, (int) ($consent->consented_interval ?? 1));
        $now = self::cycleDays($plan->billing_frequency?->value, (int) ($plan->interval_count ?: 1));

        return $agreed !== null && $now !== null && $now < $agreed;
    }

    private static function cycleDays(?string $frequency, int $interval): ?int
    {
        if ($frequency === null || ! isset(self::CYCLE_DAYS[$frequency])) {
            return null;
        }

        return self::CYCLE_DAYS[$frequency] * max(1, $interval);
    }

    private function legacyConsent(InstallmentPlan $plan, string $context): ?CustomerConsent
    {
        $hasCustomerId = $plan->customer_id !== null;
        $hasShopifyId = $plan->shopify_customer_id !== null && $plan->shopify_customer_id !== '';

        // Fail closed: no customer identity can never match a consent row.
        if (! $hasCustomerId && ! $hasShopifyId) {
            return null;
        }

        return CustomerConsent::query()
            ->where('shop_id', (int) $plan->shop_id)
            ->where('consent_context', $context)
            ->whereNull('plan_id')
            ->where(function ($q) use ($plan, $hasCustomerId, $hasShopifyId): void {
                if ($hasCustomerId) {
                    $q->orWhere('customer_id', $plan->customer_id);
                }
                if ($hasShopifyId) {
                    $q->orWhere('shopify_customer_id', $plan->shopify_customer_id);
                }
            })
            ->orderByDesc('id')
            ->first();
    }
}
