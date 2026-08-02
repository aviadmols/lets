<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of points, and WHY. Append-only: nothing here is ever edited or
 * deleted, so a balance can always be re-derived from its causes.
 *
 * `idempotency_key` (unique per shop) is the wall that makes every cause
 * name itself exactly once — see the KEY_* builders below. PointsEngine is the
 * only writer.
 */
class LoyaltyPointEvent extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'loyalty_point_events';

    /** Append-only: there is no updated_at column. */
    public const UPDATED_AT = null;

    public const KIND_EARN_PURCHASE = 'earn_purchase';
    public const KIND_JOIN = 'join_bonus';
    public const KIND_BIRTHDAY = 'birthday';
    public const KIND_SOCIAL = 'social';
    public const KIND_TIER_ENTRY = 'tier_entry';
    public const KIND_REDEEM = 'redeem_credit';
    public const KIND_ADJUST = 'admin_adjust';
    public const KINDS = [
        self::KIND_EARN_PURCHASE, self::KIND_JOIN, self::KIND_BIRTHDAY,
        self::KIND_SOCIAL, self::KIND_TIER_ENTRY, self::KIND_REDEEM, self::KIND_ADJUST,
    ];

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'amount' => 'decimal:2',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    // === Idempotency keys — the ONE place each cause is named ===

    /** Money recorded in our own payment ledger. */
    public static function keyForLedger(int $ledgerId): string
    {
        return 'earn:ledger:'.$ledgerId;
    }

    /** A Shopify order (plain sale or a Shopify-Payments subscription cycle). */
    public static function keyForShopifyOrder(string $orderId): string
    {
        return 'earn:shopify_order:'.$orderId;
    }

    public static function keyForJoin(int $accountId): string
    {
        return 'join:'.$accountId;
    }

    /** Once per calendar year — a birthday that recurs must not double-pay. */
    public static function keyForBirthday(int $accountId, int $year): string
    {
        return 'birthday:'.$accountId.':'.$year;
    }

    public static function keyForSocial(int $accountId, string $actionKey): string
    {
        return 'social:'.$accountId.':'.$actionKey;
    }

    /** Once ever per tier, even if the customer's spend later dips and recovers. */
    public static function keyForTierEntry(int $accountId, int $tierId): string
    {
        return 'tier_entry:'.$accountId.':'.$tierId;
    }
}
