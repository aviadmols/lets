<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\BindTenantFromUser;
use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformContext;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * W12 — the platform-admin shop switcher + the per-shop-action 403 fix.
 *
 * The 403: BindTenantFromUser bound the tenant on the page GET but NOT on a Livewire
 * /livewire/update action POST (Filament does not make panel middleware persistent),
 * so an entered platform admin's "Refresh products" / "Create new" 403'd. The fix
 * registers it as Livewire-persistent. The switcher lets the owner see + change the
 * entered shop from the top bar (POST platform.enter, gated to platform admins).
 */
final class ShopSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PlatformContext::exit();
        parent::tearDown();
    }

    private function wcShop(string $domain = 'store.example.com', string $name = 'WC'): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $name,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    public function test_tenant_binding_is_registered_livewire_persistent(): void
    {
        // The core 403 fix: without this, BindTenantFromUser never runs on a Livewire
        // action request → Tenant::check() is false → ShopScopedScreen 403s the action.
        $this->assertContains(BindTenantFromUser::class, Livewire::getPersistentMiddleware());
    }

    public function test_binding_survives_a_no_op_next_then_clears_on_terminate(): void
    {
        // Livewire re-runs persistent middleware through a pipeline whose $next is a
        // NO-OP (it only "applies" the binding). The OLD finally-clear unbound the tenant
        // the instant that no-op returned — BEFORE the component hydrated + re-checked
        // canAccess() → 403 on every Livewire action. The fix keeps it bound (cleared on
        // terminate instead), so the canAccess re-check now passes.
        $admin = User::factory()->platformAdmin()->create();
        $shop = $this->wcShop();
        PlatformContext::enter($shop->getKey());

        // The middleware reads $request->user(); set the resolver as the real auth
        // middleware would (a bare unit call has no user resolver on the request).
        $request = request();
        $request->setUserResolver(fn () => $admin);

        (new BindTenantFromUser)->handle($request, fn () => new Response);

        // Still bound after the no-op next — Filament's hydrateCanAuthorizeAccess passes.
        $this->assertTrue(Tenant::check());
        $this->assertSame((int) $shop->getKey(), (int) Tenant::id());

        // …and the terminate-time clear runs, so a worker never leaks it to the next request.
        $this->app->terminate();
        $this->assertFalse(Tenant::check());
    }

    public function test_platform_admin_can_enter_a_shop_via_the_switcher_route(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $shop = $this->wcShop();

        $this->actingAs($admin)
            ->post(route('platform.enter', ['shop' => $shop->getKey()]))
            ->assertRedirect();

        $this->assertSame((int) $shop->getKey(), PlatformContext::enteredShopId());
    }

    public function test_a_merchant_cannot_enter_a_shop_via_the_route(): void
    {
        $own = Shop::create([
            'shopify_domain' => 'mine.myshopify.com', 'name' => 'Mine', 'status' => Shop::STATUS_ACTIVE,
        ]);
        $merchant = User::factory()->forShop($own)->create();
        $other = $this->wcShop('other.example.com', 'Other');

        $this->actingAs($merchant)
            ->post(route('platform.enter', ['shop' => $other->getKey()]))
            ->assertForbidden();

        // The non-admin never parks a foreign shop selection.
        $this->assertNull(PlatformContext::enteredShopId());
    }

    public function test_switcher_renders_shops_for_a_platform_admin(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);
        $shop = $this->wcShop('switch.example.com', 'Switch Test');

        $html = view('filament.platform.shop-switcher')->render();

        $this->assertStringContainsString($shop->displayDomain(), $html);
        $this->assertStringContainsString('platform/enter/'.$shop->getKey(), $html);
    }

    public function test_switcher_renders_nothing_for_a_merchant(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'm.myshopify.com', 'name' => 'M', 'status' => Shop::STATUS_ACTIVE,
        ]);
        $this->actingAs(User::factory()->forShop($shop)->create());

        // The @if(isPlatformAdmin) guard short-circuits before any markup.
        $this->assertSame('', trim(view('filament.platform.shop-switcher')->render()));
    }

    public function test_viewing_as_banner_shows_the_woocommerce_shop_name(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);
        $shop = $this->wcShop('banner.example.com', 'Banner WC');
        PlatformContext::enter($shop->getKey());

        // Previously read shopify_domain (null for WC) → blank; now displayDomain().
        $this->assertStringContainsString('banner.example.com', view('filament.platform.viewing-as-banner')->render());
    }
}
