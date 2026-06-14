<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable row per money movement. Append-only in spirit: a charge is
 * written `pending`, then transitions to a terminal state. No charge happens
 * without a row here. Tenant-scoped via BelongsToShop.
 */
class PaymentLedger extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'payment_ledger';

    public const CONTEXT_DEPOSIT = 'deposit';
    public const CONTEXT_INSTALLMENT = 'installment';
    public const CONTEXT_RECURRING = 'recurring';
    public const CONTEXT_UPSELL = 'upsell';
    public const CONTEXT_RETRY = 'retry';
    public const CONTEXT_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    /**
     * Hardened mass-assignment: shop_id (auto-stamped) and status (advanced only
     * via Ledger::transition, the canonical money machine) cannot be set by a raw
     * create/update. A row is born `pending` (the column default); Ledger::open
     * and Ledger::transition own every status write.
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_response_masked' => 'array',
        ];
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
