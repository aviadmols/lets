<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OLD handle of a shop, kept for a while after a platform admin renamed it so
 * bookmarks and mailed links still land: `<old>.app.lets.co.il` answers with a
 * 301 to the shop's current host until `expires_at`.
 *
 * Platform data, NOT tenant data: it maps a public host to a shop before any
 * tenant is known (exactly like `shops.handle` itself), so it carries shop_id
 * but no BelongsToShop scope — it is read only by ShopHandleResolver and
 * written only by ShopHandleChanger.
 */
class ShopHandleAlias extends Model
{
    // === CONSTANTS ===
    /** How long an old handle keeps redirecting. */
    public const TTL_DAYS = 30;

    public const UPDATED_AT = null;

    protected $table = 'shop_handle_aliases';

    protected $fillable = ['shop_id', 'handle', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }
}
