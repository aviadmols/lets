<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's membership in one shop's club.
 *
 * The row's EXISTENCE is the opt-in: the program accrues nothing for a shopper
 * who never joined, however much they spend. Balances here are caches of the
 * append-only loyalty_point_events ledger and are moved only by PointsEngine,
 * under a row lock.
 *
 * `customer_ref` is the same derived identity the rest of the app uses (Shopify
 * numeric customer id / WooCommerce user id / billing email for a guest).
 * `birthday` is personal data — it is redacted with the rest on GDPR request.
 */
class LoyaltyAccount extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'loyalty_accounts';

    /** Nothing here may be mass-assigned into another tenant or a fabricated balance. */
    protected $guarded = ['id', 'shop_id', 'points_balance', 'lifetime_points', 'lifetime_spend'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'birthday_set_at' => 'datetime',
            'joined_at' => 'datetime',
            'points_balance' => 'integer',
            'lifetime_points' => 'integer',
            'lifetime_spend' => 'decimal:2',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LoyaltyPointEvent::class, 'loyalty_account_id');
    }

    /**
     * A HUMAN label for the member — name, else email, else the raw reference.
     * Mirrors InstallmentPlan::customerLabel()'s precedence.
     */
    public function label(): string
    {
        foreach ([$this->customer_name, $this->customer_email, $this->customer_ref] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return __('common.none');
    }

    /** The birthday can be set ONCE — a movable birthday is a repeatable bonus. */
    public function birthdayLocked(): bool
    {
        return $this->birthday_set_at !== null;
    }

    /** Has this member already claimed a given one-click social action? */
    public function hasClaimed(string $idempotencyKey): bool
    {
        return $this->events()->where('idempotency_key', $idempotencyKey)->exists();
    }
}
