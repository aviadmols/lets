<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply to EVERY tenant-owned model. Adds a global scope that constrains all
 * queries to the current Tenant, and auto-stamps shop_id on create.
 *
 * RELEASE-BLOCKER RULE: never call withoutGlobalScopes() in product code. Only
 * audited platform-admin services may bypass tenancy.
 */
trait BelongsToShop
{
    // === CONSTANTS ===
    public const SHOP_FOREIGN_KEY = 'shop_id';

    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            if (empty($model->{self::SHOP_FOREIGN_KEY}) && Tenant::check()) {
                $model->{self::SHOP_FOREIGN_KEY} = Tenant::id();
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, self::SHOP_FOREIGN_KEY);
    }
}

/**
 * The global scope itself. Fails closed: with no tenant bound, a tenant-owned
 * query returns nothing rather than everything.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn(BelongsToShop::SHOP_FOREIGN_KEY),
            Tenant::id()
        );
    }
}
