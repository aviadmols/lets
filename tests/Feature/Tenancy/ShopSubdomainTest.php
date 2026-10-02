<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\ShopHandleChanger;
use App\Domain\Tenancy\ShopHosts;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\TwoFactorChallenge;
use App\Http\Middleware\BindTenantFromUser;
use App\Http\Middleware\PersistEmbeddedContext;
use App\Http\Middleware\ResolveShopFromHost;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\EmbeddedSession;
use App\Support\PlatformContext;
use App\Support\RequestedShop;
use App\Support\Tenant;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * RELEASE-BLOCKER (plan phase 2): `<handle>.app.lets.co.il` is one store's admin.
 * The host is a WALL on top of the user → tenant binding, never a source of it:
 *   - a merchant on another store's host is signed out and sent home — nothing
 *     of that store is bound or drawn;
 *   - a platform admin on a store host is entered into exactly that store;
 *   - an unknown host is a 404 (never a login form), an old handle a 301;
 *   - logins land merchants on their own host; embedded flows on the root host
 *     and signed analytics links are unaffected.
 */
final class ShopSubdomainTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ROOT = 'app.lets.co.il';

    private const PROBE = '/test/subdomain/probe';

    private const PASSWORD = 'password';

    private Shop $shopA;

    private Shop $shopB;

    private InstallmentPlan $planA;

    private InstallmentPlan $planB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.subdomains_enabled' => true,
            'tenancy.admin_root_host' => self::ROOT,
            'app.url' => 'https://'.self::ROOT,
        ]);

        // The REAL host + binding middleware, in panel order, behind a probe that
        // reports what the request was bound to and which plans the scope shows.
        Route::middleware([ResolveShopFromHost::class, 'web', 'auth', BindTenantFromUser::class])
            ->get(self::PROBE, fn () => response()->json([
                'bound_shop_id' => Tenant::id(),
                'plan_ids' => InstallmentPlan::query()->pluck('id')->all(),
            ]));

        [$this->shopA, $this->planA] = $this->shopWithPlan('shop-a.myshopify.com');
        [$this->shopB, $this->planB] = $this->shopWithPlan('shop-b.myshopify.com');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        RequestedShop::clear();
        PlatformContext::exit();
        parent::tearDown();
    }

    // === Isolation: the wall ===

    public function test_a_merchant_on_their_own_host_is_bound_to_their_shop(): void
    {
        $this->actingAs($this->merchant($this->shopA))
            ->getJson($this->on('shop-a', self::PROBE))
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $this->shopA->id)
            ->assertJsonPath('plan_ids', [$this->planA->id]);
    }

    public function test_a_merchant_on_another_stores_host_is_signed_out_and_sent_home(): void
    {
        $merchantA = $this->merchant($this->shopA);

        $response = $this->actingAs($merchantA)->get($this->on('shop-b', self::PROBE));

        $response->assertRedirect(ShopHosts::shopAdminUrl('shop-a', ShopHosts::LOGIN_PATH));
        $this->assertGuest();
        $this->assertStringNotContainsString((string) $this->planB->id, (string) $response->getContent());
        $this->assertStringNotContainsString('shop-b', (string) $response->headers->get('Location'));
    }

    public function test_the_real_admin_page_of_another_store_never_renders(): void
    {
        $response = $this->actingAs($this->merchant($this->shopA))->get($this->on('shop-b', '/admin'));

        $response->assertRedirect(ShopHosts::shopAdminUrl('shop-a', ShopHosts::LOGIN_PATH));
        $this->assertStringNotContainsString('shop-b.myshopify.com', (string) $response->getContent());
        $this->assertGuest();
    }

    public function test_a_merchant_on_their_own_real_admin_page_gets_it(): void
    {
        $this->actingAs($this->merchant($this->shopA))
            ->get($this->on('shop-a', '/admin'))
            ->assertOk();
    }

    public function test_a_platform_admin_on_a_store_host_is_scoped_to_that_store_only(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        PlatformContext::enter($this->shopB->id); // a stale session selection must lose to the host

        $this->actingAs($admin)
            ->getJson($this->on('shop-a', self::PROBE))
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $this->shopA->id)
            ->assertJsonPath('plan_ids', [$this->planA->id]);
    }

    public function test_a_platform_admin_on_the_root_host_keeps_platform_mode(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->getJson($this->on(null, self::PROBE))
            ->assertOk()
            ->assertJsonPath('bound_shop_id', null)
            ->assertJsonPath('plan_ids', []);
    }

    public function test_platform_context_reads_the_host_first_then_the_session(): void
    {
        PlatformContext::enter($this->shopB->id);
        $this->assertSame($this->shopB->id, PlatformContext::enteredShopId());
        $this->assertFalse(PlatformContext::isEnteredByHost());

        RequestedShop::set($this->shopA);
        $this->assertSame($this->shopA->id, PlatformContext::enteredShopId());
        $this->assertTrue(PlatformContext::isEnteredByHost());
    }

    // === Resolution ===

    public function test_an_unknown_host_is_a_404_never_the_login_form(): void
    {
        foreach (['nobody-here', 'www', 'a.b'] as $label) {
            $response = $this->get('https://'.$label.'.'.self::ROOT.'/admin/login');

            $response->assertNotFound();
            $response->assertSee(__('tenancy.no_such_store.title'));
            $response->assertDontSee('type="password"', false);
        }
    }

    public function test_the_root_host_and_a_known_store_host_show_the_login(): void
    {
        $this->get($this->on(null, '/admin/login'))->assertOk();
        $this->get($this->on('shop-a', '/admin/login'))->assertOk();
    }

    public function test_an_old_handle_301s_to_the_current_host_keeping_the_path(): void
    {
        app(ShopHandleChanger::class)->change($this->shopA, 'shop-a-renamed');

        $this->get($this->on('shop-a', '/admin/login?x=1'))
            ->assertStatus(301)
            ->assertRedirect('https://shop-a-renamed.'.self::ROOT.'/admin/login?x=1');
    }

    public function test_with_the_switch_off_a_store_host_resolves_nothing(): void
    {
        config(['tenancy.subdomains_enabled' => false]);

        $this->actingAs($this->merchant($this->shopA))
            ->getJson($this->on('shop-b', self::PROBE))
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $this->shopA->id);
        $this->assertSame('https://'.self::ROOT.'/admin', $this->shopA->adminUrl(), 'Off: APP_URL, as before.');
    }

    // === Livewire updates keep the tenant ===

    public function test_the_host_middleware_is_livewire_persistent_and_first(): void
    {
        $persistent = Livewire::getPersistentMiddleware();
        $this->assertContains(ResolveShopFromHost::class, $persistent);

        $panel = Filament::getPanel('admin')->getMiddleware();
        $panel = array_values(array_filter($panel, fn (string $m): bool => ! str_starts_with($m, 'panel:')));
        $this->assertSame(ResolveShopFromHost::class, $panel[0], 'First in the panel stack.');
    }

    public function test_a_livewire_update_on_a_store_host_keeps_that_tenant(): void
    {
        $merchantA = $this->merchant($this->shopA);

        $this->runPersistent($merchantA, 'shop-a');

        $this->assertSame($this->shopA->id, Tenant::id());
    }

    public function test_a_livewire_update_on_another_stores_host_is_refused(): void
    {
        $merchantA = $this->merchant($this->shopA);

        try {
            $this->runPersistent($merchantA, 'shop-b');
            $this->fail('A foreign-host Livewire update was allowed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse(Tenant::check());
    }

    // === Login lands on the right host ===

    public function test_a_merchant_signing_in_on_the_root_lands_on_their_store_host(): void
    {
        $user = $this->merchant($this->shopA);

        $this->passwordStep($user)->assertRedirect(ShopHosts::shopAdminUrl('shop-a'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_merchant_signing_in_on_another_stores_host_is_sent_home(): void
    {
        RequestedShop::set($this->shopB);

        $this->passwordStep($this->merchant($this->shopA))->assertRedirect(ShopHosts::shopAdminUrl('shop-a'));
    }

    public function test_a_merchant_signing_in_on_their_own_host_stays(): void
    {
        RequestedShop::set($this->shopA);

        $this->passwordStep($this->merchant($this->shopA))->assertRedirect(Filament::getUrl());
    }

    public function test_a_platform_admin_signing_in_on_the_root_stays_on_the_root(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->passwordStep($admin)->assertRedirect(TwoFactorChallenge::getUrl());

        Livewire::test(TwoFactorChallenge::class)
            ->set('data.code', $this->currentCode())
            ->call('verify')
            ->assertRedirect(Filament::getUrl());
    }

    public function test_the_two_factor_step_also_lands_a_merchant_on_their_host(): void
    {
        $user = User::factory()->forShop($this->shopA)->withTwoFactor()->create();

        $this->passwordStep($user)->assertRedirect(TwoFactorChallenge::getUrl());

        Livewire::test(TwoFactorChallenge::class)
            ->set('data.code', $this->currentCode())
            ->call('verify')
            ->assertRedirect(ShopHosts::shopAdminUrl('shop-a'));

        $this->assertAuthenticatedAs($user);
    }

    // === Embedded flows on the root host are untouched ===

    public function test_an_embedded_session_on_the_root_host_is_unaffected(): void
    {
        $merchant = $this->merchant($this->shopA);

        $this->actingAs($merchant)
            ->withSession([
                EmbeddedSession::SESSION_PLATFORM => EmbeddedSession::PLATFORM_WOOCOMMERCE,
                PersistEmbeddedContext::SESSION_EMBEDDED => true,
            ])
            ->getJson($this->on(null, self::PROBE))
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $this->shopA->id);

        $this->actingAs($merchant)->get($this->on(null, '/admin'))->assertOk();
        $this->assertAuthenticatedAs($merchant);
    }

    public function test_an_embedded_binding_that_disagrees_with_the_host_is_refused(): void
    {
        Tenant::set($this->shopB); // what EmbeddedAuthenticate would have bound
        RequestedShop::set($this->shopA);

        $this->expectException(HttpException::class);
        (new BindTenantFromUser)->handle(Request::create($this->on('shop-a', '/admin')), fn () => new Response);
    }

    // === Links ===

    public function test_open_links_point_at_the_store_host(): void
    {
        $this->assertSame('https://shop-a.'.self::ROOT.'/admin', $this->shopA->adminUrl());
        $this->assertSame('https://'.self::ROOT.'/admin/shops', ShopHosts::rootAdminUrl('/shops'));
    }

    public function test_the_switcher_and_banner_link_to_store_hosts(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create());
        RequestedShop::set($this->shopA);

        $switcher = view('filament.platform.shop-switcher')->render();
        $this->assertStringContainsString('https://shop-b.'.self::ROOT.'/admin', $switcher);
        $this->assertStringNotContainsString('platform/enter/', $switcher);

        $banner = view('filament.platform.viewing-as-banner')->render();
        $this->assertStringContainsString('shop-a.myshopify.com', $banner);
        $this->assertStringContainsString('https://'.self::ROOT.'/admin/shops', $banner);
    }

    public function test_customer_links_built_on_a_store_host_stay_on_the_root(): void
    {
        // Inside a request on a store host: admin routes stay there, a customer /
        // signed link is built — and signed — on APP_URL (owner decision C).
        URL::setRequest(Request::create($this->on('shop-a', '/admin')));
        RequestedShop::set($this->shopA);

        $this->assertStringStartsWith($this->on('shop-a', '/admin'), route('filament.admin.auth.login'));

        $signed = URL::signedRoute('woocommerce.embed.login', ['token' => 'x']);
        $this->assertStringStartsWith($this->on(null, '/embed/woocommerce/x'), $signed);
        $this->assertTrue(URL::hasValidSignature(Request::create($signed)));
    }

    // === Hosts, proxies, cookies ===

    public function test_only_our_hosts_are_trusted(): void
    {
        $patterns = ShopHosts::trustedHostPatterns();
        $trusted = fn (string $host): bool => collect($patterns)->contains(fn (string $p): bool => preg_match('{'.$p.'}i', $host) === 1);

        foreach ([self::ROOT, 'shop-a.'.self::ROOT, 'healthcheck.railway.app', 'web-production.up.railway.app', 'localhost'] as $host) {
            $this->assertTrue($trusted($host), $host);
        }
        foreach (['evil.example', self::ROOT.'.evil.example', 'lets.co.il', 'xapp.lets.co.il'] as $host) {
            $this->assertFalse($trusted($host), $host);
        }
    }

    public function test_a_forwarded_host_header_cannot_pick_a_store(): void
    {
        // X-Forwarded-Host is not a trusted proxy header: the REAL Host (the root)
        // decides, so a merchant of A on the root is not walled off by a header.
        $this->actingAs($this->merchant($this->shopA))
            ->withHeaders(['X-Forwarded-Host' => 'shop-b.'.self::ROOT])
            ->getJson($this->on(null, self::PROBE))
            ->assertOk()
            ->assertJsonPath('bound_shop_id', $this->shopA->id);
    }

    public function test_the_session_cookie_spans_every_store_host_in_production(): void
    {
        $keys = ['APP_ENV', 'SHOP_SUBDOMAINS_ENABLED', 'ADMIN_ROOT_HOST', 'SESSION_DOMAIN', 'SESSION_COOKIE'];
        $saved = array_map(fn (string $k): array => [$_SERVER[$k] ?? null, $_ENV[$k] ?? null], array_combine($keys, $keys));
        $set = function (string $key, ?string $value): void {
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);

                return;
            }
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv($key.'='.$value);
        };

        try {
            $set('APP_ENV', 'production');
            $set('ADMIN_ROOT_HOST', self::ROOT);
            $set('SESSION_DOMAIN', null);
            $set('SESSION_COOKIE', null);

            $set('SHOP_SUBDOMAINS_ENABLED', 'false');
            $off = require config_path('session.php');
            $this->assertNull($off['domain'], 'Switch off: host-only, as before.');

            // Switch off + a SESSION_DOMAIN already on the deploy: honoured exactly
            // as before, and the cookie is NOT renamed (nobody is signed out).
            $set('SESSION_DOMAIN', '.'.self::ROOT);
            $offWithDomain = require config_path('session.php');
            $this->assertSame('.'.self::ROOT, $offWithDomain['domain']);
            $this->assertStringEndsNotWith('_shared', $offWithDomain['cookie']);
            $set('SESSION_DOMAIN', null);

            $set('SHOP_SUBDOMAINS_ENABLED', 'true');
            $on = require config_path('session.php');
            $this->assertSame('.'.self::ROOT, $on['domain']);
            $this->assertStringEndsWith('_shared', $on['cookie'], 'A new cookie name: a stale host-only one cannot shadow it.');

            $set('SESSION_DOMAIN', '');
            $this->assertNull((require config_path('session.php'))['domain'], 'An empty SESSION_DOMAIN opts out.');

            $set('SESSION_DOMAIN', null);
            $set('APP_ENV', 'local');
            $this->assertNull((require config_path('session.php'))['domain'], 'Local stays host-only.');
        } finally {
            foreach ($saved as $key => [$server, $env]) {
                $set($key, $server ?? $env);
            }
        }
    }

    // === Helpers ===

    /** An absolute URL on a store host, or on the root when $handle is null. */
    private function on(?string $handle, string $path): string
    {
        return 'https://'.($handle !== null ? $handle.'.' : '').self::ROOT.$path;
    }

    /** Run the Livewire-persistent pair the way a /livewire/update does. */
    private function runPersistent(User $user, string $handle): void
    {
        $request = Request::create($this->on($handle, '/livewire/update'), 'POST');
        $request->headers->set(BindTenantFromUser::LIVEWIRE_HEADER, 'true');
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        app(ResolveShopFromHost::class)->handle($request, fn () => new Response);
        (new BindTenantFromUser)->handle($request, fn () => new Response);
    }

    private function passwordStep(User $user): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', self::PASSWORD)
            ->call('authenticate');
    }

    private function currentCode(): string
    {
        return app(Google2FA::class)->getCurrentOtp(UserFactory::TWO_FACTOR_TEST_SECRET);
    }

    private function merchant(Shop $shop): User
    {
        return User::factory()->forShop($shop)->create();
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function shopWithPlan(string $domain): array
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
