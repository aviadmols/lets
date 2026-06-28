<?php

namespace App\Domain\Billing;

use App\Models\Shop;

/**
 * The single SEAM through which every per-tier limit is enforced. Code at a
 * creation/activation point asks PlanGate, never the enum directly — so when paid
 * tiers arrive the enforcement logic is already wired and only the NUMBERS (in
 * BillingPlan::limits()) change.
 *
 * Designed to be NON-BLOCKING for FREE: FREE's limits are all unlimited / all
 * features on, so allows() is always true and within() is always true. Nothing a
 * current shop does is gated today — this is structure, not a behaviour change.
 *
 * Fail-OPEN by design (the opposite of tenant isolation, deliberately): this gate
 * protects the VENDOR's unit economics, not customer data. An unknown feature key
 * or a missing limits row must never 500 a merchant or wrongly block a paid
 * capability — it resolves permissively. (Tenant isolation, which protects money +
 * PII, fails CLOSED elsewhere; this gate protects pricing and fails OPEN.)
 *
 *   PlanGate::for($shop)->allows(BillingPlan::FEATURE_POST_PURCHASE)        // bool
 *   PlanGate::for($shop)->limit(BillingPlan::LIMIT_MAX_UPSELL_FLOWS)        // ?int (null = unlimited)
 *   PlanGate::for($shop)->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $n)   // room for one more?
 */
final class PlanGate
{
    // === CONSTANTS ===
    /** Boolean default when a feature key is absent from a tier's matrix (fail open). */
    private const DEFAULT_FEATURE_ALLOWED = true;

    /**
     * @param BillingPlan $plan the tier this gate evaluates against
     * @param array<string, int|bool|null> $limits its resolved gate matrix
     */
    private function __construct(
        private readonly BillingPlan $plan,
        private readonly array $limits,
    ) {
    }

    /** Build a gate for a shop, reading its resolved BillingPlan (defaults to FREE). */
    public static function for(Shop $shop): self
    {
        return self::forPlan($shop->billingPlan());
    }

    /**
     * Build a gate directly for a plan (handy when the tier is already known).
     * Snapshots the tier's matrix from BillingPlan — the ONE source of the numbers.
     */
    public static function forPlan(BillingPlan $plan): self
    {
        return new self($plan, $plan->limits());
    }

    /**
     * Build a gate against an EXPLICIT matrix for a given tier. Used by:
     *   - admin tier-preview tooling ("what would Growth allow?");
     *   - the gate test-suite, to prove a CAPPED tier blocks past its limit before
     *     any paid tier ships (the seam works without new wiring — paid tiers are
     *     just numbers in BillingPlan::limits()).
     * The matrix shape is identical to BillingPlan::limits(); missing keys fall
     * back to the same fail-open defaults as a real tier.
     *
     * @param array<string, int|bool|null> $limits
     */
    public static function withLimits(BillingPlan $plan, array $limits): self
    {
        return new self($plan, $limits);
    }

    /** The tier this gate is evaluating against. */
    public function plan(): BillingPlan
    {
        return $this->plan;
    }

    /**
     * Is a BOOLEAN feature enabled on this tier? Unknown keys resolve to allowed
     * (fail open). FREE → always true.
     */
    public function allows(string $feature): bool
    {
        $value = $this->limits[$feature] ?? self::DEFAULT_FEATURE_ALLOWED;

        return (bool) $value;
    }

    /**
     * The COUNTER limit for a key: an int cap, or null = UNLIMITED. An unknown key
     * (or a key not present in the tier's matrix) resolves to unlimited (fail open).
     * FREE → always null (unlimited).
     */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? BillingPlan::UNLIMITED;

        return $value === null ? null : (int) $value;
    }

    /**
     * Is there room for ONE MORE, given the current count? True when the tier is
     * unlimited for this key OR the current count is strictly below the cap. Call
     * this at ACTIVATION (not draft creation): a merchant may draft beyond the cap
     * but cannot activate past it.
     *
     * FREE → always true (unlimited), so the storefront/admin never blocks today.
     */
    public function within(string $key, int $currentCount): bool
    {
        $cap = $this->limit($key);

        return $cap === null || $currentCount < $cap;
    }

    /**
     * The remaining headroom for a counter key (null = unlimited). UI uses this to
     * show "3 of 5 used" / an upgrade nudge. Never negative.
     */
    public function remaining(string $key, int $currentCount): ?int
    {
        $cap = $this->limit($key);

        return $cap === null ? null : max(0, $cap - $currentCount);
    }
}
