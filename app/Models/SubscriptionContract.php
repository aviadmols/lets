<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
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

    /** Only these are eligible for a billing attempt. */
    public const BILLABLE_STATUSES = [self::STATUS_ACTIVE];

    /** shop_id is stamped by BelongsToShop; the mirror is written only by ContractMirror. */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'lines' => 'array',
            'interval_count' => 'integer',
            'next_billing_date' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function billingAttempts(): HasMany
    {
        return $this->hasMany(SubscriptionBillingAttempt::class, 'subscription_contract_id');
    }

    public function isBillable(): bool
    {
        return in_array((string) $this->status, self::BILLABLE_STATUSES, true);
    }

    /** The numeric tail of the GID — what Shopify's REST-ish surfaces call the id. */
    public function shopifyNumericId(): string
    {
        $gid = (string) $this->shopify_gid;
        $pos = strrpos($gid, '/');

        return $pos !== false ? substr($gid, $pos + 1) : $gid;
    }
}
