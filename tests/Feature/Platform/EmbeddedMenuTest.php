<?php

namespace Tests\Feature\Platform;

use App\Filament\Pages\HomeDashboard;
use App\Filament\Pages\ManagePayPlusConnection;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShopResource;
use App\Filament\Resources\ShopResource\Pages\ViewShop;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\TeamMemberResource;
use App\Models\Shop;
use App\Models\User;
use App\Support\EmbeddedSession;
use App\Support\Tenant;
use App\Support\Ui\EmbeddedMenu;
use App\Support\Ui\PanelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The menu a WooCommerce merchant sees when LETS is rendered inside wp-admin,
 * and the platform owner's per-shop control over it.
 *
 * Laws under test:
 *   - Hiding is not security: an area the owner switched off disappears from the
 *     sidebar AND 403s on its own URL.
 *   - A shop nobody has configured (embedded_menu = null) loses nothing, and Home
 *     is reachable whatever the list says — a menu item that 403s is a bug report.
 *   - Credentials (PayPlus) and logins (Team) are never inside somebody else's
 *     admin, allow-list or no allow-list.
 *   - The standalone admin at app.lets.co.il is completely untouched by all of it.
 *   - Only the platform owner writes the list.
 */
final class EmbeddedMenuTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'menu.example.com',
            'name' => 'Menu Shop',
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The gate itself (PanelAccess::embeddedAllows) ===

    public function test_outside_an_embedded_session_nothing_is_filtered(): void
    {
        $this->bind(['home']); // the narrowest possible list…

        // …but no embedded session, so the standalone admin is unchanged.
        $this->assertTrue(PanelAccess::embeddedAllows(ProductResource::class));
        $this->assertTrue(PanelAccess::embeddedAllows(ManagePayPlusConnection::class));
        $this->assertTrue(PanelAccess::embeddedAllows(TeamMemberResource::class));
    }

    public function test_a_null_menu_allows_everything(): void
    {
        $this->bind(null);
        $this->embed();

        $this->assertNull($this->shop->fresh()->embeddedMenu());
        $this->assertTrue(PanelAccess::embeddedAllows(ProductResource::class));
        $this->assertTrue(PanelAccess::embeddedAllows(SubscriptionResource::class));
    }

    public function test_an_area_that_is_off_is_denied_and_one_that_is_on_passes(): void
    {
        $this->bind([EmbeddedMenu::AREA_SUBSCRIPTIONS]);
        $this->embed();

        $this->assertTrue(PanelAccess::embeddedAllows(SubscriptionResource::class));
        $this->assertFalse(PanelAccess::embeddedAllows(ProductResource::class));

        // A resource's sub-pages follow their resource (Filament authorises them
        // against it), so allowing "subscriptions" allows opening one.
        $this->assertTrue(PanelAccess::embeddedAllows(
            ListSubscriptions::class,
        ));
        $this->assertFalse(PanelAccess::embeddedAllows(
            ListProducts::class,
        ));
    }

    public function test_home_survives_an_empty_list(): void
    {
        $this->bind([]);
        $this->embed();

        $this->assertSame([], $this->shop->fresh()->embeddedMenu());
        $this->assertTrue(PanelAccess::embeddedAllows(HomeDashboard::class));
    }

    public function test_credentials_and_logins_are_never_embedded(): void
    {
        $this->bind(null); // everything allowed…
        $this->embed();

        // …except these two, which are not a menu choice.
        $this->assertFalse(PanelAccess::embeddedAllows(ManagePayPlusConnection::class));
        $this->assertFalse(PanelAccess::embeddedAllows(TeamMemberResource::class));
        $this->assertFalse(PanelAccess::embeddedAllows(ShopResource::class));
    }

    /** A screen nobody has mapped yet must not vanish silently. */
    public function test_an_unmapped_screen_fails_open(): void
    {
        $this->bind([EmbeddedMenu::AREA_SUBSCRIPTIONS]);
        $this->embed();

        $this->assertNull(EmbeddedMenu::areaFor('App\\Filament\\Pages\\SomethingShippedNextRelease'));
        $this->assertTrue(PanelAccess::embeddedAllows('App\\Filament\\Pages\\SomethingShippedNextRelease'));
    }

    // === The screens (nav registration + the URL) ===

    public function test_a_hidden_area_leaves_the_sidebar_and_403s_its_url(): void
    {
        $merchant = User::factory()->forShop($this->shop)->create();
        $this->shop->forceFill(['embedded_menu' => [EmbeddedMenu::AREA_SUBSCRIPTIONS]])->save();

        $this->actingAs($merchant)->withSession([
            EmbeddedSession::SESSION_PLATFORM => EmbeddedSession::PLATFORM_WOOCOMMERCE,
        ]);

        // Hidden: no nav entry, and the URL itself is denied.
        $this->get(ProductResource::getUrl('index'))->assertForbidden();

        // Allowed: still there.
        $this->get(SubscriptionResource::getUrl('index'))->assertSuccessful();

        // Home is always reachable, and so the merchant is never stranded.
        $this->get(HomeDashboard::getUrl())->assertSuccessful();

        // Never inside WordPress, whatever the list says.
        $this->get(ManagePayPlusConnection::getUrl())->assertForbidden();
        $this->get(TeamMemberResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_same_merchant_signed_in_directly_keeps_every_screen(): void
    {
        $merchant = User::factory()->forShop($this->shop)->create();
        $this->shop->forceFill(['embedded_menu' => [EmbeddedMenu::AREA_SUBSCRIPTIONS]])->save();

        // No embedded session key → the standalone admin, unfiltered.
        $this->actingAs($merchant);

        $this->get(ProductResource::getUrl('index'))->assertSuccessful();
        $this->get(TeamMemberResource::getUrl('index'))->assertSuccessful();
    }

    public function test_the_nav_registration_follows_the_same_answer(): void
    {
        $merchant = User::factory()->forShop($this->shop)->create();
        $this->shop->forceFill(['embedded_menu' => [EmbeddedMenu::AREA_SUBSCRIPTIONS]])->save();

        $this->actingAs($merchant);
        Tenant::set($this->shop->fresh());
        $this->embed();

        $this->assertFalse(ProductResource::shouldRegisterNavigation());
        $this->assertTrue(SubscriptionResource::shouldRegisterNavigation());
        $this->assertFalse(TeamMemberResource::shouldRegisterNavigation());
        $this->assertFalse(ManagePayPlusConnection::shouldRegisterNavigation());
    }

    // === The platform owner's control ===

    public function test_the_platform_owner_writes_the_menu(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // set() the whole array rather than callAction($data): Filament's test
        // helper dot-flattens $data and would only overwrite the first N indices
        // of the prefilled list, leaving the tail behind.
        Livewire::actingAs($admin)
            ->test(ViewShop::class, ['record' => $this->shop])
            ->mountAction('embeddedMenu')
            ->set('mountedActionsData.0.areas', [
                EmbeddedMenu::AREA_SUBSCRIPTIONS, EmbeddedMenu::AREA_PAYMENTS, 'not-an-area',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(
            [EmbeddedMenu::AREA_SUBSCRIPTIONS, EmbeddedMenu::AREA_PAYMENTS],
            $this->shop->fresh()->embeddedMenu(),
        );
    }

    /** Ticking every box stores "no restriction", so next release's screen shows. */
    public function test_selecting_everything_stores_null(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->shop->forceFill(['embedded_menu' => [EmbeddedMenu::AREA_PAYMENTS]])->save();

        Livewire::actingAs($admin)
            ->test(ViewShop::class, ['record' => $this->shop])
            ->mountAction('embeddedMenu')
            ->set('mountedActionsData.0.areas', EmbeddedMenu::keys())
            ->callMountedAction();

        $this->assertNull($this->shop->fresh()->embedded_menu);
    }

    public function test_a_merchant_cannot_reach_the_action(): void
    {
        $merchant = User::factory()->forShop($this->shop)->create();

        Livewire::actingAs($merchant)
            ->test(ViewShop::class, ['record' => $this->shop])
            ->assertForbidden();

        $this->assertNull($this->shop->fresh()->embedded_menu);
    }

    // === helpers ===

    /** @param list<string>|null $menu */
    private function bind(?array $menu): void
    {
        $this->shop->forceFill(['embedded_menu' => $menu])->save();
        Tenant::set($this->shop->fresh());
    }

    /** Pretend this request arrived through /embed/woocommerce/{token}. */
    private function embed(): void
    {
        session([EmbeddedSession::SESSION_PLATFORM => EmbeddedSession::PLATFORM_WOOCOMMERCE]);
    }
}
