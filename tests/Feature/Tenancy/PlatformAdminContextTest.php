<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Resources\ShopResource;
use App\Http\Middleware\BindTenantFromUser;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\PlatformContext;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RELEASE-BLOCKER (W2): the platform-admin "Enter / Exit shop" context switch must
 * isolate exactly like a merchant — entering shop A binds ONLY A (the same global
 * scope), and a merchant must be wholly denied the Shops list. Proven through the
 * SAME production binding middleware (BindTenantFromUser) the panel uses, plus the
 * role gates, the acting-as audit actor, and the provisioning command.
 *
 * Complements CrossUserIsolationTest: that proves merchant↔merchant isolation +
 * "platform admin with no selection sees zero rows" (which MUST stay true here).
 */
final class PlatformAdminContextTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHOP_A_DOMAIN = 'shop-a.myshopify.com';
    private const SHOP_B_DOMAIN = 'shop-b.myshopify.com';
    private const PROBE_LIST = '/test/platform/plans';

    protected function setUp(): void
    {
        parent::setUp();

        // Probe route guarded by the REAL production binding middleware. The bound
        // shop id + the plan ids visible through the global scope are returned, so
        // the test exercises the genuine "entered shop → scoped query" seam.
        Route::middleware(['web', 'auth', BindTenantFromUser::class])->group(function (): void {
            Route::get(self::PROBE_LIST, function () {
                return response()->json([
                    'bound_shop_id' => Tenant::id(),
                    'plan_ids' => InstallmentPlan::query()->pluck('id')->all(),
                ]);
            });
        });
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        PlatformContext::exit();
        parent::tearDown();
    }

    // === A. Enter / Exit binds exactly the entered shop ===

    public function test_platform_admin_in_platform_mode_sees_zero_per_shop_rows(): void
    {
        $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $this->provisionShopWithPlan(self::SHOP_B_DOMAIN);
        $platform = User::factory()->platformAdmin()->create();

        // No shop entered → UNBOUND → the headline fail-closed behaviour holds.
        $this->actingAs($platform)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', null)
            ->assertJsonPath('plan_ids', []);
    }

    public function test_entered_platform_admin_sees_only_that_shops_data_never_the_other(): void
    {
        [$shopA, $planA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        [$shopB, $planB] = $this->provisionShopWithPlan(self::SHOP_B_DOMAIN);
        $platform = User::factory()->platformAdmin()->create();

        // ENTER shop A → bound to A only. B's plan is invisible.
        PlatformContext::enter($shopA->id);
        $this->actingAs($platform)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shopA->id)
            ->assertJsonPath('plan_ids', [$planA->id]);

        // SWITCH to shop B → bound to B only. A's plan is invisible (no leak).
        PlatformContext::enter($shopB->id);
        $this->actingAs($platform)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shopB->id)
            ->assertJsonPath('plan_ids', [$planB->id]);
    }

    public function test_exit_returns_platform_admin_to_unbound_platform_mode(): void
    {
        [$shopA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $platform = User::factory()->platformAdmin()->create();

        PlatformContext::enter($shopA->id);
        $this->assertSame($shopA->id, PlatformContext::enteredShopId());

        PlatformContext::exit();
        $this->assertNull(PlatformContext::enteredShopId());

        $this->actingAs($platform)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', null)
            ->assertJsonPath('plan_ids', []);
    }

    public function test_merchant_entered_shop_id_is_ignored_cannot_escape_own_shop(): void
    {
        // A merchant who somehow set the session key must NEVER be bound to another
        // shop — the middleware reads the entered id only on the platform-admin
        // branch. A merchant stays pinned to their own shop, fail-closed.
        [$shopA, $planA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        [$shopB, $planB] = $this->provisionShopWithPlan(self::SHOP_B_DOMAIN);
        $merchantA = User::factory()->forShop($shopA)->create();

        PlatformContext::enter($shopB->id); // hostile attempt to view shop B

        $this->actingAs($merchantA)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shopA->id) // still their own shop
            ->assertJsonPath('plan_ids', [$planA->id]);
    }

    public function test_entered_shop_that_no_longer_exists_falls_back_to_platform_mode(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        PlatformContext::enter(999999); // stale / hard-deleted shop id
        $this->actingAs($platform)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', null);

        // The stale selection was dropped (middleware called PlatformContext::exit()).
        $this->assertNull(PlatformContext::enteredShopId());
    }

    // === B. ShopResource hard gate: merchant gets NOTHING ===

    public function test_platform_admin_can_access_shops_list_and_merchant_cannot(): void
    {
        [$shopA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $merchant = User::factory()->forShop($shopA)->create();
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($merchant);
        $this->assertFalse(ShopResource::canAccess());
        $this->assertFalse(ShopResource::canViewAny());
        $this->assertFalse(ShopResource::shouldRegisterNavigation());

        $this->actingAs($platform);
        $this->assertTrue(ShopResource::canAccess());
        $this->assertTrue(ShopResource::canViewAny());
        $this->assertTrue(ShopResource::shouldRegisterNavigation());
    }

    public function test_merchant_hitting_the_shops_index_route_is_denied(): void
    {
        [$shopA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $merchant = User::factory()->forShop($shopA)->create();

        // A direct URL to the platform Shops list is forbidden for a merchant
        // (Filament denies when canAccess() is false) — nav hidden is not enough.
        $response = $this->actingAs($merchant)->get(ShopResource::getUrl('index'));
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_platform_admin_can_list_all_shops_query_unscoped(): void
    {
        $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $this->provisionShopWithPlan(self::SHOP_B_DOMAIN);

        // Shop is the tenant (NOT BelongsToShop): the resource query returns ALL
        // shops; isolation is enforced by gating the RESOURCE to platform admins.
        $this->assertSame(2, Shop::query()->count());
    }

    // === C. Acting-as audit ===

    public function test_timeline_write_while_entered_is_attributed_to_platform_admin(): void
    {
        [$shopA, $planA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform);
        PlatformContext::enter($shopA->id);

        Tenant::run($shopA, function () use ($planA): void {
            Timeline::record('charge_succeeded', ['amount' => 50], planId: $planA->id);
        });

        $event = Tenant::run($shopA, fn () => ActivityEvent::query()->latest('id')->first());
        $this->assertNotNull($event);
        $this->assertSame(PlatformContext::ACTOR_PREFIX . $platform->id, $event->actor);
    }

    public function test_timeline_write_without_entered_defaults_to_system(): void
    {
        [$shopA, $planA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);

        Tenant::run($shopA, function () use ($planA): void {
            Timeline::record('charge_succeeded', ['amount' => 50], planId: $planA->id);
        });

        $event = Tenant::run($shopA, fn () => ActivityEvent::query()->latest('id')->first());
        $this->assertSame(ActivityEvent::ACTOR_SYSTEM, $event->actor);
    }

    public function test_explicit_actor_is_not_overwritten_by_platform_admin(): void
    {
        [$shopA, $planA] = $this->provisionShopWithPlan(self::SHOP_A_DOMAIN);
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform);
        PlatformContext::enter($shopA->id);

        Tenant::run($shopA, function () use ($planA): void {
            Timeline::record('webhook_received', [], planId: $planA->id, actor: ActivityEvent::ACTOR_WEBHOOK);
        });

        $event = Tenant::run($shopA, fn () => ActivityEvent::query()->latest('id')->first());
        $this->assertSame(ActivityEvent::ACTOR_WEBHOOK, $event->actor);
    }

    // === D. Provisioning ===

    public function test_is_platform_admin_stays_mass_assignment_guarded(): void
    {
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky-w2@example.com',
            'password' => 'secret-secret',
            'is_platform_admin' => true, // must be ignored (guarded)
        ]);

        $this->assertFalse($user->refresh()->isPlatformAdmin());
    }

    public function test_create_admin_command_creates_a_working_platform_admin(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'owner@example.com', '--name' => 'Owner'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isPlatformAdmin());
        $this->assertNull($user->shop_id);
        $this->assertTrue($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
    }

    public function test_create_admin_command_is_idempotent_and_promotes_existing_user(): void
    {
        $merchant = User::factory()->create(['shop_id' => null, 'email' => 'promote@example.com']);
        $this->assertFalse($merchant->isPlatformAdmin());

        $this->artisan('platform:create-admin', ['email' => 'promote@example.com'])
            ->assertSuccessful();

        // Same row promoted, not duplicated.
        $this->assertSame(1, User::query()->where('email', 'promote@example.com')->count());
        $this->assertTrue($merchant->refresh()->isPlatformAdmin());
    }

    // === Helpers ===

    /**
     * Create an independent shop + one active plan owned by it.
     *
     * @return array{0: Shop, 1: InstallmentPlan}
     */
    private function provisionShopWithPlan(string $domain): array
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);

        $plan = Tenant::run($shop, function (): InstallmentPlan {
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'total_amount' => 300,
                'total_charged' => 0,
                'installment_amount' => 100,
                'currency' => 'ILS',
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        });

        Tenant::clear();

        return [$shop, $plan];
    }
}
