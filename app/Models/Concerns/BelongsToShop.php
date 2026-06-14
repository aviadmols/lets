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
    // Canonical value lives on TenantScope (a class), because PHP forbids
    // accessing a *trait* constant via the trait name — and TenantScope needs it.
    public const SHOP_FOREIGN_KEY = TenantScope::SHOP_FOREIGN_KEY;

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

    /**
     * AUDITED cross-tenant query — the ONLY sanctioned bypass of the tenant
     * global scope, for platform-level work that legitimately spans all shops
     * (e.g. the scheduler fan-out, which then dispatches one shop-bound job per
     * row). NEVER use this to render or return another shop's data to a request.
     *
     * Named explicitly so the isolation audit can grep every call site.
     */
    public static function acrossAllTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}

/**
 * The global scope itself. Fails closed: with no tenant bound, a tenant-owned
 * query returns nothing rather than everything.
 */
final class TenantScope implements Scope
{
    // === CONSTANTS ===
    public const SHOP_FOREIGN_KEY = 'shop_id';

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn(self::SHOP_FOREIGN_KEY),
            Tenant::id()
        );
    }
}
