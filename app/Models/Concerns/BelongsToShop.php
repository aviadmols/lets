<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

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
        static::addGlobalScope(new TenantScope);

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

    /** A predicate no row satisfies — the whole of "no tenant bound". */
    public const NO_TENANT_PREDICATE = '1 = 0';

    public function apply(Builder $builder, Model $model): void
    {
        // No tenant → NOTHING, stated outright. `where(shop_id, null)` compiles
        // to `shop_id IS NULL`, which is only empty while every tenant table
        // keeps shop_id NOT NULL; a single nullable column would turn "no shop"
        // into "every orphan row". 1 = 0 does not depend on the schema.
        if (! Tenant::check()) {
            $builder->whereRaw(self::NO_TENANT_PREDICATE);

            return;
        }

        $builder->where(
            $model->qualifyColumn(self::SHOP_FOREIGN_KEY),
            Tenant::id()
        );
    }
}
