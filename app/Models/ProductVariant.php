<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A variant of a cached Product. Per-variant SKU + price live here — plan
 * templates target a specific variant (or all variants when their
 * product_variant_id is null). Tenant-scoped (shop_id + BelongsToShop). The
 * import job upserts by (shop_id, product_id, external_variant_id).
 */
class ProductVariant extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'product_variants';

    /** shop_id is auto-stamped by BelongsToShop; guard it against raw writes. */
    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    // === Relations ===

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(ProductSubscriptionPlan::class);
    }
}
