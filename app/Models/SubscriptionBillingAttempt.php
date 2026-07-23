<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing attempt WE asked Shopify to make for one contract cycle.
 *
 * Not a ledger row: Shopify processes the payment and owns the money truth. This
 * records the REQUEST (so the scanner is idempotent — unique per contract+cycle)
 * and the outcome Shopify reported via webhook. The idempotency key is also sent
 * to Shopify on the mutation itself, so even a crash between our INSERT and the
 * API call cannot double-bill a cycle.
 */
class SubscriptionBillingAttempt extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'subscription_billing_attempts';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CHALLENGED = 'challenged';

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SubscriptionContract::class, 'subscription_contract_id');
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
