<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * A compiled GDPR data-request export (Shopify `customers/data_request`). Holds
 * the structured JSON of everything we hold for one customer, persisted so the
 * merchant can retrieve and fulfil the request within Shopify's 30-day window.
 *
 * Tenant-scoped (BelongsToShop): another shop can never read this export. The
 * `export` JSON DOES contain the customer's own PII (that is the point — it is
 * THEIR data, going back to them); it is never logged or surfaced cross-tenant.
 */
class DataRequestExport extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'data_request_exports';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_FULFILLED = 'fulfilled';

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'export' => 'array',
            'requested_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }
}
