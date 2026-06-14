<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit, audited consent to charge a saved PayPlus token in the future.
 * Required before any installments/recurring/upsell charge. Tenant-scoped.
 */
class CustomerConsent extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    public const CONTEXT_INSTALLMENTS = 'installments';
    public const CONTEXT_RECURRING = 'recurring';
    public const CONTEXT_UPSELL = 'upsell';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }
}
