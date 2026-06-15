<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local cache of an upstream catalog product (Shopify now, WooCommerce later).
 * Source-agnostic: `source` + `external_id` identify the upstream record; the UI
 * + plan templates read this cache instead of hitting the source on every render.
 * Tenant-scoped (shop_id + BelongsToShop). The import job upserts by
 * (shop_id, source, external_id).
 */
class Product extends Model
{
    use BelongsToShop;

    // === CONSTANTS — status taxonomy (allow-lists guard sanitization) ===
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    /** Soft-removed upstream: hidden from pickers but plans are KEPT. */
    public const STATUS_UNLISTED = 'unlisted';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DRAFT, self::STATUS_UNLISTED];

    public const ONLINE_PUBLISHED = 'published';
    public const ONLINE_UNPUBLISHED = 'unpublished';
    public const ONLINE_STATUSES = [self::ONLINE_PUBLISHED, self::ONLINE_UNPUBLISHED];

    public const SOURCE_SHOPIFY = 'shopify';
    public const SOURCE_WOOCOMMERCE = 'woocommerce';
    public const SOURCES = [self::SOURCE_SHOPIFY, self::SOURCE_WOOCOMMERCE];

    /**
     * shop_id is auto-stamped by BelongsToShop — guard it so a raw create()/
     * update() cannot set or spoof tenancy.
     */
    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'updated_at_external' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    // === Relations ===

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(ProductSubscriptionPlan::class);
    }

    // === Display helpers (list/detail screens read these) ===

    /** The lowest-position variant — what the list row represents. */
    public function primaryVariant(): ?ProductVariant
    {
        return $this->variants()
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    /** SKU shown in the list row (primary variant's SKU). */
    public function skuForList(): ?string
    {
        return $this->primaryVariant()?->sku;
    }

    /** Price shown in the list row (primary variant's price). */
    public function priceForList(): ?string
    {
        return $this->primaryVariant()?->price;
    }
}
