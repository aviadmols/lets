<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\BindTenantFromUser;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RELEASE-BLOCKER (the headline): a logged-in MERCHANT USER is bound to their OWN
 * shop by the PRODUCTION binding middleware (BindTenantFromUser) and can NEVER
 * read another store's data. Proven in BOTH directions, plus: a record fetched by
 * id across the tenant boundary 404s (never leaks), and a shopless user is denied
 * panel access.
 *
 * This complements TenantIsolationTest (which proves the global scope at the Shop
 * level) by proving the USER -> Shop binding that the production panel relies on.
 */
final class CrossUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHOP_A_DOMAIN = 'shop-a.myshopify.com';
    private const SHOP_B_DOMAIN = 'shop-b.myshopify.com';
    private const PROBE_LIST = '/test/tenant/plans';
    private const PROBE_FIND = '/test/tenant/plan';

    protected function setUp(): void
    {
        parent::setUp();

        // Probe routes guarded by the SAME production binding middleware the panel
        // uses. They exercise the real seam: auth user -> bound shop -> scoped query.
        Route::middleware(['web', 'auth', BindTenantFromUser::class])->group(function (): void {
            // (a) list query — must return ONLY the acting user's shop rows.
            Route::get(self::PROBE_LIST, function () {
                return response()->json([
                    'bound_shop_id' => Tenant::id(),
                    'plan_ids' => InstallmentPlan::query()->pluck('id')->all(),
                    'ledger_ids' => PaymentLedger::query()->pluck('id')->all(),
                ]);
            });

            // (b) find-by-id — a cross-tenant id must 404, never return the row.
            Route::get(self::PROBE_FIND.'/{id}', function (int $id) {
                $plan = InstallmentPlan::query()->findOrFail($id); // scope hides foreign rows
                return response()->json(['id' => $plan->id]);
            });
        });
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_user_a_sees_only_shop_a_data_and_user_b_only_shop_b(): void
    {
        [$shopA, $userA, $planA, $ledgerA] = $this->provisionShop(self::SHOP_A_DOMAIN);
        [$shopB, $userB, $planB, $ledgerB] = $this->provisionShop(self::SHOP_B_DOMAIN);

        // Acting as User A: only Shop A's plan + ledger are visible.
        $this->actingAs($userA)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shopA->id)
            ->assertJsonPath('plan_ids', [$planA->id])
            ->assertJsonPath('ledger_ids', [$ledgerA->id]);

        // Tenant cleared after the request (no worker/request leak).
        $this->assertNull(Tenant::current());

        // Acting as User B: only Shop B's data — the mirror direction.
        $this->actingAs($userB)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $shopB->id)
            ->assertJsonPath('plan_ids', [$planB->id])
            ->assertJsonPath('ledger_ids', [$ledgerB->id]);
    }

    public function test_requesting_other_shops_record_by_id_returns_404_not_the_row(): void
    {
        [, $userA, $planA] = $this->provisionShop(self::SHOP_A_DOMAIN);
        [, $userB, $planB] = $this->provisionShop(self::SHOP_B_DOMAIN);

        // A can fetch its own plan.
        $this->actingAs($userA)->getJson(self::PROBE_FIND.'/'.$planA->id)
            ->assertOk()
            ->assertJsonPath('id', $planA->id);

        // A asking for B's plan id → 404 (findOrFail under the scope), NEVER B's row.
        $this->actingAs($userA)->getJson(self::PROBE_FIND.'/'.$planB->id)
            ->assertNotFound();

        // And the mirror: B cannot reach A's plan by id.
        $this->actingAs($userB)->getJson(self::PROBE_FIND.'/'.$planA->id)
            ->assertNotFound();
    }

    public function test_shopless_merchant_user_is_denied_panel_access(): void
    {
        // A user with no shop and not a platform admin: the binding middleware must
        // FAIL CLOSED (403) and bind no tenant.
        $shopless = User::factory()->create(['shop_id' => null]);

        $this->actingAs($shopless)->getJson(self::PROBE_LIST)
            ->assertForbidden();

        $this->assertNull(Tenant::current());

        // The Filament gate agrees: canAccessPanel is false for a shopless user.
        $this->assertFalse($shopless->canAccessPanel($this->fakePanel()));
    }

    public function test_merchant_user_can_access_panel_platform_admin_can_too(): void
    {
        [$shopA, $userA] = $this->provisionShop(self::SHOP_A_DOMAIN);
        $platform = User::factory()->platformAdmin()->create();

        $this->assertTrue($userA->canAccessPanel($this->fakePanel()));
        $this->assertTrue($platform->canAccessPanel($this->fakePanel()));
        $this->assertTrue($platform->isPlatformAdmin());
        $this->assertNull($platform->shop_id);
    }

    public function test_platform_admin_request_binds_no_tenant_so_scope_fails_closed(): void
    {
        // Seed two shops with data; a platform admin must NOT silently see it all
        // through normal panel queries — with no tenant bound, the scope returns 0.
        $this->provisionShop(self::SHOP_A_DOMAIN);
        $this->provisionShop(self::SHOP_B_DOMAIN);

        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform)->getJson(self::PROBE_LIST)
            ->assertOk()
            ->assertJsonPath('bound_shop_id', null)
            ->assertJsonPath('plan_ids', [])     // fail closed: zero rows, never all
            ->assertJsonPath('ledger_ids', []);
    }

    public function test_is_platform_admin_is_guarded_from_mass_assignment(): void
    {
        // A privilege flag must never be set from fill()/create() input.
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'secret-secret',
            'is_platform_admin' => true, // must be ignored (guarded)
        ]);

        $this->assertFalse($user->refresh()->isPlatformAdmin());
    }

    // === Helpers ===

    /**
     * Stand up an INDEPENDENT tenant: its own Shop, a merchant User linked by
     * shop_id, and one plan + one ledger row owned by that shop.
     *
     * @return array{0: Shop, 1: User, 2: InstallmentPlan, 3: PaymentLedger}
     */
    private function provisionShop(string $domain): array
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);

        $user = User::factory()->forShop($shop)->create();

        [$plan, $ledger] = Tenant::run($shop, function (): array {
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'total_amount' => 300,
                'total_charged' => 0,
                'installment_amount' => 100,
                'currency' => 'ILS',
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            $ledger = PaymentLedger::create([
                'charge_context' => PaymentLedger::CONTEXT_INSTALLMENT,
                'idempotency_key' => 'installment:'.$plan->shop_id.':'.$plan->id.':1',
                'amount' => 100,
                'currency' => 'ILS',
            ]);

            return [$plan, $ledger];
        });

        // Clear so the acting request must rebind via the middleware (not ambient).
        Tenant::clear();

        return [$shop, $user, $plan, $ledger];
    }

    private function fakePanel(): \Filament\Panel
    {
        return \Filament\Facades\Filament::getPanel('admin');
    }
}
