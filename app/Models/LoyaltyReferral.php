<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase that came through a member's referral link.
 *
 * The row is keyed on the ORDER (unique per shop), which is what stops a
 * redelivered webhook from paying the referrer twice for one friend's purchase.
 * It also gives the merchant — and the member — an answerable history: "who did
 * I bring, and what did it earn me".
 */
class LoyaltyReferral extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'loyalty_referrals';

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'order_amount' => 'decimal:2',
            'points_awarded' => 'integer',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'referrer_account_id');
    }
}
