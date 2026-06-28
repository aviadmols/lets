<?php

namespace App\Domain\Billing;

/**
 * The SaaS monetization tier catalog — a GLOBAL platform model (NOT tenant-scoped;
 * read-only catalog data shared by every shop). This is the canonical "global
 * platform model" exception to the BelongsToShop tenant rule: a tier list is the
 * same for all shops, so it is code, not a per-shop row.
 *
 * OWNER'S LOCKED DECISION: exactly ONE active plan right now — FREE. Every shop is
 * on Free, nothing is charged, every limit is unlimited and every feature is on.
 * Nothing about a current shop changes when a gate is added.
 *
 * The SHAPE is built so paid tiers drop in with NO rework:
 *   - add a case (e.g. STARTER) below;
 *   - give it a row in limits() with the numbers/flags for that tier;
 *   - (later) wire its Shopify AppSubscription price in BillingService.
 * PlanGate, the migration default, the accessor, and the admin all read THIS — so
 * adding a tier touches exactly two places: the enum case + its limits() row.
 *
 * Money rails note (do NOT confuse them): a BillingPlan is what the MERCHANT pays
 * the APP VENDOR (via Shopify AppSubscription, USD, flat $/mo). It is unrelated to
 * payment_ledger, which is what the merchant's CUSTOMERS pay the MERCHANT via
 * PayPlus. Separate tables, separate code paths. This enum only describes vendor
 * revenue + the gate matrix.
 */
enum BillingPlan: string
{
    // === CONSTANTS — limit dimensions (the gate-matrix keys) ===
    // Counter gates: an int cap, or null = UNLIMITED. PlanGate::within() reads these.
    public const LIMIT_MAX_SUBSCRIPTIONS = 'max_subscriptions';
    public const LIMIT_MAX_UPSELL_FLOWS = 'max_upsell_flows';

    // Boolean feature gates: true = allowed. PlanGate::allows() reads these.
    public const FEATURE_POST_PURCHASE = 'post_purchase_enabled';
    public const FEATURE_RECURRING = 'recurring_enabled';
    public const FEATURE_INSTALLMENTS = 'installments_enabled';
    public const FEATURE_CUSTOM_EMAIL_BRANDING = 'custom_email_branding';
    public const FEATURE_PRIORITY_QUEUE = 'priority_queue';

    /** Every counter dimension (null = unlimited in a plan's limits row). */
    public const COUNTER_KEYS = [
        self::LIMIT_MAX_SUBSCRIPTIONS,
        self::LIMIT_MAX_UPSELL_FLOWS,
    ];

    /** Every boolean feature dimension. */
    public const FEATURE_KEYS = [
        self::FEATURE_POST_PURCHASE,
        self::FEATURE_RECURRING,
        self::FEATURE_INSTALLMENTS,
        self::FEATURE_CUSTOM_EMAIL_BRANDING,
        self::FEATURE_PRIORITY_QUEUE,
    ];

    /** Sentinel: a counter limit of null means "no cap". */
    public const UNLIMITED = null;

    // === Cases — FREE is the only ACTIVE tier today ===
    // To add a paid tier later: add a case here, then a limits() row below.
    case FREE = 'free';

    // case STARTER = 'starter';   // TODO(paid-tiers): example — uncomment + add limits() row.
    // case GROWTH  = 'growth';
    // case PRO     = 'pro';

    /** The plan applied when a shop has no (or an unknown) plan column value. */
    public static function default(): self
    {
        return self::FREE;
    }

    /**
     * Resolve a raw column string to a case, falling back to the default when the
     * value is null, blank, or not a known tier. Fail-safe: an unrecognised stored
     * plan never throws and never silently grants a paid tier — it lands on FREE.
     */
    public static function fromCode(?string $code): self
    {
        $code = is_string($code) ? trim($code) : '';

        return $code !== '' ? (self::tryFrom($code) ?? self::default()) : self::default();
    }

    /** The i18n key for this plan's display name (billing.plan_tier.<code>). */
    public function labelKey(): string
    {
        return "billing.plan_tier.{$this->value}";
    }

    /** Localised display name ("Free"). */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * Flat monthly price in USD (App Store billing is USD). FREE is 0; paid tiers
     * set their price here when added. This is the snapshot a future AppSubscription
     * is created at — BillingService reads it, not the live admin.
     */
    public function monthlyPriceUsd(): float
    {
        return match ($this) {
            self::FREE => 0.0,
            // self::STARTER => 29.0, self::GROWTH => 79.0, self::PRO => 199.0,
        };
    }

    /** Free-trial length in days for this tier (0 = no trial; FREE needs none). */
    public function trialDays(): int
    {
        return match ($this) {
            self::FREE => 0,
            // self::STARTER, self::GROWTH, self::PRO => 14,
        };
    }

    /** Is this a paid tier (drives whether a Shopify AppSubscription is required)? */
    public function isPaid(): bool
    {
        return $this->monthlyPriceUsd() > 0.0;
    }

    /**
     * The full gate matrix for this tier: every counter dimension (int cap, or
     * UNLIMITED/null) + every boolean feature. PlanGate reads ONLY this map, so the
     * gate behaviour of a new tier is fully described by its row here.
     *
     * FREE = everything unlimited / every feature on, so nothing a current shop
     * does is ever blocked. A paid tier just replaces null with a number and true
     * with false where it should be capped.
     *
     * @return array<string, int|bool|null>
     */
    public function limits(): array
    {
        return match ($this) {
            self::FREE => [
                self::LIMIT_MAX_SUBSCRIPTIONS => self::UNLIMITED,
                self::LIMIT_MAX_UPSELL_FLOWS => self::UNLIMITED,
                self::FEATURE_POST_PURCHASE => true,
                self::FEATURE_RECURRING => true,
                self::FEATURE_INSTALLMENTS => true,
                self::FEATURE_CUSTOM_EMAIL_BRANDING => true,
                self::FEATURE_PRIORITY_QUEUE => true,
            ],

            // TODO(paid-tiers): one row per tier — the ONLY numbers to set. Example:
            // self::STARTER => [
            //     self::LIMIT_MAX_SUBSCRIPTIONS => 50,
            //     self::LIMIT_MAX_UPSELL_FLOWS => 0,
            //     self::FEATURE_POST_PURCHASE => false,
            //     self::FEATURE_RECURRING => true,
            //     self::FEATURE_INSTALLMENTS => true,
            //     self::FEATURE_CUSTOM_EMAIL_BRANDING => false,
            //     self::FEATURE_PRIORITY_QUEUE => false,
            // ],
        };
    }
}
