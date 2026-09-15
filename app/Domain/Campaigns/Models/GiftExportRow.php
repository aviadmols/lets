<?php

namespace App\Domain\Campaigns\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One line of a gift export file.
 *
 * `fields` holds a customer's address, and the app deliberately keeps no copy of
 * those — so it is ENCRYPTED at rest and the row is short-lived: replaced by the
 * shop's next export, and pruned once the download window has passed.
 */
class GiftExportRow extends Model
{
    use BelongsToShop;
    use Prunable;

    // === CONSTANTS ===
    protected $table = 'gift_export_rows';

    protected $guarded = [];

    protected $casts = [
        'recipient' => 'array',
        'fields' => 'encrypted:array',
        'position' => 'integer',
    ];

    /** Everything past the download window, every shop — run by the scheduler. */
    public function prunable(): Builder
    {
        return static::acrossAllTenants()
            ->where('created_at', '<', now()->subHours(GiftExportRun::VISIBLE_HOURS));
    }
}
