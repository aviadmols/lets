<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\TenantScope;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With NO tenant bound, a tenant-owned query returns nothing — by a predicate
 * that is false on its own (1 = 0), not by `shop_id IS NULL`, which is only
 * empty for as long as every tenant table keeps shop_id NOT NULL.
 */
final class TenantScopeFailsClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_no_tenant_compiles_to_a_predicate_that_matches_nothing(): void
    {
        Tenant::clear();

        $sql = MerchantPortalAppearance::query()->toSql();

        $this->assertStringContainsString(TenantScope::NO_TENANT_PREDICATE, $sql);
        $this->assertStringNotContainsString('is null', strtolower($sql));
    }

    public function test_no_tenant_reads_no_rows_while_a_bound_tenant_reads_its_own(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'scope.myshopify.com',
            'name' => 'Scope',
            'status' => Shop::STATUS_ACTIVE,
        ]);

        Tenant::set($shop);
        MerchantPortalAppearance::current();
        $this->assertSame(1, MerchantPortalAppearance::query()->count());

        Tenant::clear();
        $this->assertSame(0, MerchantPortalAppearance::query()->count());
        $this->assertSame(1, MerchantPortalAppearance::acrossAllTenants()->count(), 'the audited bypass is unchanged');
    }
}
