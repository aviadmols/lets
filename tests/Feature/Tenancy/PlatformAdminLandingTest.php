<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Pages\Insights\Joins;
use App\Filament\Resources\ShopResource;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION (W3): the platform owner must be able to log into /admin. /admin is
 * Filament's redirect to the first screen the signed-in user may see: for a
 * merchant that is the panel home (Overview → Joins, shop-scoped); for a platform
 * admin in "platform mode" no tenant is bound, every shop-scoped screen is hidden,
 * and the redirect lands on the Shops list instead of 403-ing. A shopless
 * non-platform user stays denied.
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

    public function test_merchant_landing_reaches_the_overview_home(): void
    {
        $shop = Shop::create([
            'shopify_domain' => self::SHOP_DOMAIN,
            'name' => self::SHOP_DOMAIN,
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $merchant = User::factory()->forShop($shop)->create();

        $this->actingAs($merchant)->get(self::PANEL_HOME)->assertRedirect(Joins::getUrl());
        $this->actingAs($merchant)->get(Joins::getUrl())->assertOk();
    }

    public function test_shopless_non_platform_user_is_denied(): void
    {
        // Neither a shop nor platform admin → fail closed (the middleware aborts 403).
        $user = User::factory()->create(['shop_id' => null]);

        $this->actingAs($user)->get(self::PANEL_HOME)->assertForbidden();
    }

    // === canAccess() unit assertions (deterministic, no middleware) ===

    public function test_the_shop_scoped_home_is_closed_to_a_platform_admin_in_platform_mode(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform);

        // No tenant bound (platform mode): the home is shop data, so it stays shut —
        // the owner lands on the Shops list, which IS open to them.
        $this->assertFalse(Joins::canAccess());
        $this->assertTrue(ShopResource::canAccess());
    }

    public function test_the_home_denies_a_shopless_non_platform_user(): void
    {
        $user = User::factory()->create(['shop_id' => null]);

        $this->actingAs($user);

        $this->assertFalse(Joins::canAccess());
    }
}
