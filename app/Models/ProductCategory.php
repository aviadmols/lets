<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One upstream category a cached Product sits in (WooCommerce product_cat). Written only
 * by ProductUpserter from the catalogue sync; read by the bundle builder to list
 * categories and by a category bundle to find its products. Tenant-scoped (shop_id +
 * BelongsToShop). Unique by (shop_id, product_id, external_category_id).
 */
class ProductCategory extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'product_categories';

    /** shop_id is auto-stamped by BelongsToShop; guard it against raw writes. */
    protected $guarded = ['shop_id'];

    // === Relations ===

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * THIS shop's categories that hold at least one active product: id, name and how many
     * active products each holds, by name. One row per category even if a rename left two
     * spellings behind (the newest wins).
     *
     * @return Collection<int, array{id: string, name: string, count: int}>
     */
    public static function withActiveProducts(): Collection
    {
        return static::query()
            ->whereHas('product', fn ($q) => $q->where('status', Product::STATUS_ACTIVE))
            ->orderByDesc('updated_at')
            ->get(['external_category_id', 'name', 'product_id'])
            ->groupBy('external_category_id')
            ->map(fn (Collection $rows, string|int $id): array => [
                'id' => (string) $id,
                'name' => (string) $rows->first()->name,
                'count' => $rows->pluck('product_id')->unique()->count(),
            ])
            ->sortBy('name', SORT_NATURAL)
            ->values();
    }
}
