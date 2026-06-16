<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Pages\HomeDashboard;
use App\Filament\Resources\ShopResource;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION (W3): the platform owner must be able to log into /admin. The panel's
 * default landing page (HomeDashboard, slug '/') is shop-scoped — for a platform
 * admin in "platform mode" no tenant is bound, so the un-patched canAccess()
 * (Tenant::check()) returned false and Filament 403'd the owner on /admin. The fix
 * lets the platform admin LOAD '/' and mount() bounces them to the Shops list, while
 * a merchant still gets the dashboard and a shopless non-platform user stays denied.
 */
final class PlatformAdminLandingTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PANEL_HOME = '/admin';
    private const SHOP_DOMAIN = 'shop-home.myshopify.com';

    public function test_platform_admin_landing_redirects_to_shops_not_403(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        // The bug: this returned 403. The fix: a redirect to the Shops/Accounts list.
        $this->actingAs($platform)->get(self::PANEL_HOME)
            ->assertRedirect(ShopResource::getUrl());
    }

    public function test_merchant_landing_reaches_the_dashboard(): void
    {
        $shop = Shop::create([
            'shopify_domain' => self::SHOP_DOMAIN,
            'name' => self::SHOP_DOMAIN,
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $merchant = User::factory()->forShop($shop)->create();

        $this->actingAs($merchant)->get(self::PANEL_HOME)->assertOk();
    }

    public function test_shopless_non_platform_user_is_denied(): void
    {
        // Neither a shop nor platform admin → fail closed (the middleware aborts 403).
        $user = User::factory()->create(['shop_id' => null]);

        $this->actingAs($user)->get(self::PANEL_HOME)->assertForbidden();
    }

    // === canAccess() unit assertions (deterministic, no middleware) ===

    public function test_home_dashboard_canaccess_allows_platform_admin_in_platform_mode(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform);

        // No tenant bound (platform mode) — yet the owner may LOAD '/' to be redirected.
        $this->assertTrue(HomeDashboard::canAccess());
    }

    public function test_home_dashboard_canaccess_denies_shopless_non_platform(): void
    {
        $user = User::factory()->create(['shop_id' => null]);

        $this->actingAs($user);

        $this->assertFalse(HomeDashboard::canAccess());
    }
}
