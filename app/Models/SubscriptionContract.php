<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A local MIRROR of a Shopify SubscriptionContract — never a source of truth.
 *
 * This is the inversion that separates the Shopify-Payments rail from the PayPlus
 * one: on the PayPlus rail the plan + ledger ARE the money truth (we hold the
 * token, we charge); here Shopify holds the card, processes the payment, and owns
 * the contract. This row exists so the merchant screen, the due-cycle scanner and
 * the customer's personal area can read without an API round trip per row.
 * Shopify wins every disagreement; `synced_at` says how stale we are.
 *
 * Status vocabulary is SHOPIFY'S (ACTIVE/PAUSED/CANCELLED/EXPIRED/FAILED),
 * mirrored verbatim — two state machines owned by two systems must not share an
 * enum, and there is no guarded transitionTo() here because we never decide a
 * transition, we only record what Shopify already did.
 */
class SubscriptionContract extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'subscription_contracts';

    /** Shopify's contract statuses, verbatim. */
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_PAUSED = 'PAUSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_CANCELLED,
        self::STATUS_EXPIRED, self::STATUS_FAILED,
    ];

    /** Only these are eligible for a billing attempt — and never while awaiting activation. */
    public const BILLABLE_STATUSES = [self::STATUS_ACTIVE];

    /** Statuses after which a contract cannot be started or billed again. */
    public const TERMINAL_STATUSES = [self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    /**
     * Shopify's `interval` vocabulary → our BillingFrequency values, for the
     * screens and audience filters that speak one cadence language across both
     * rails. `interval_count` is deliberately not folded in (every 2 weeks is
     * still "weekly" to a filter), exactly as the account offers treat plans.
     *
     * @var array<string, string>
     */
    public const INTERVAL_FREQUENCY = [
        'DAY' => 'daily',
        'WEEK' => 'weekly',
        'MONTH' => 'monthly',
        'YEAR' => 'yearly',
    ];

    /**
     * shop_id is stamped by BelongsToShop. Shopify's fields are written only by ContractMirror;
     * the three activation columns are LETS state, written only by ContractActivation.
     */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'lines' => 'array',
            'interval_count' => 'integer',
            'next_billing_date' => 'datetime',
            'synced_at' => 'datetime',
            'awaiting_activation_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function billingAttempts(): HasMany
    {
        return $this->hasMany(SubscriptionBillingAttempt::class, 'subscription_contract_id');
    }

    /**
     * Billable = Shopify says ACTIVE and the customer is not still to start it. The hold is
     * checked here, not only by the scanner, so "charge now" and a queued attempt obey it too.
     */
    public function isBillable(): bool
    {
        return in_array((string) $this->status, self::BILLABLE_STATUSES, true) && ! $this->awaitsActivation();
    }

    /**
     * Held for its customer: paid at checkout, not started (ContractActivation). A cancelled
     * contract is not waiting for anything.
     */
    public function awaitsActivation(): bool
    {
        return $this->awaiting_activation_at !== null
            && $this->activated_at === null
            && ! in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    /** The scanner's half of the hold: contracts never held, or already started. */
    public function scopeNotAwaitingActivation(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q->whereNull('awaiting_activation_at')->orWhereNotNull('activated_at'));
    }

    /** The numeric tail of the GID — what Shopify's REST-ish surfaces call the id. */
    public function shopifyNumericId(): string
    {
        $gid = (string) $this->shopify_gid;
        $pos = strrpos($gid, '/');

        return $pos !== false ? substr($gid, $pos + 1) : $gid;
    }
}
