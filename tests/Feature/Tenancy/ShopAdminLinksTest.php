<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\ShopHosts;
use App\Filament\Resources\ShopResource\Pages\ListShops;
use App\Filament\Resources\TeamMemberResource\Pages\CreateTeamMember;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plan phase 3 — every link into a store's admin is built by ONE builder,
 * Shop::adminUrl(): the store's own host once subdomains are on, the root
 * admin (APP_URL) while they are off. Onboarding ends by showing it.
 */
final class ShopAdminLinksTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ROOT = 'app.lets.co.il';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.subdomains_enabled' => true,
            'tenancy.admin_root_host' => self::ROOT,
            'app.url' => 'https://'.self::ROOT,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_admin_url_is_the_one_builder(): void
    {
        $shop = $this->shop('builder.myshopify.com');

        $this->assertSame('https://builder.'.self::ROOT.'/admin', $shop->adminUrl());
        $this->assertSame('https://builder.'.self::ROOT.'/admin/login', $shop->adminUrl(ShopHosts::LOGIN_PATH));
        $this->assertSame('https://builder.'.self::ROOT.'/admin/settings', $shop->adminUrl('settings'));

        config(['tenancy.subdomains_enabled' => false]);
        $this->assertSame('https://'.self::ROOT.'/admin/login', $shop->adminUrl(ShopHosts::LOGIN_PATH));
    }

    public function test_woocommerce_connect_reveals_the_store_admin_address(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create());

        $page = Livewire::test(ListShops::class)
            ->callAction('addWooCommerce', ['domain' => 'https://www.connect-me.co.il/'])
            ->assertSee('https://connect-me-co-il.'.self::ROOT.'/admin');

        $this->assertSame('https://connect-me-co-il.'.self::ROOT.'/admin', $page->get('wcConnection')['admin_url'] ?? null);
    }

    public function test_the_first_run_banner_shows_the_store_address(): void
    {
        $shop = $this->shop('first-run.myshopify.com');
        $merchant = User::factory()->forShop($shop)->create();

        $this->actingAs($merchant)
            ->get('https://first-run.'.self::ROOT.'/admin')
            ->assertOk()
            ->assertSee(__('tenancy.onboarding.admin_url_intro'))
            ->assertSee('https://first-run.'.self::ROOT.'/admin');
    }

    public function test_adding_a_colleague_says_where_they_sign_in(): void
    {
        $shop = $this->shop('team.myshopify.com');
        $this->actingAs(User::factory()->forShop($shop)->create());
        Tenant::set($shop);

        Livewire::test(CreateTeamMember::class)
            ->fillForm(['name' => 'Colleague', 'email' => 'colleague@example.com', 'password' => 'a-strong-password-1'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(User::query()->where('email', 'colleague@example.com')->exists());

        $sent = new Notifications;
        $sent->mount();
        $this->assertStringContainsString(
            'https://team.'.self::ROOT.'/admin/login',
            (string) $sent->notifications->map(fn ($n) => $n->getBody())->implode(' '),
        );
    }

    public function test_the_create_user_command_prints_the_store_login(): void
    {
        $shop = $this->shop('cli.myshopify.com');

        $this->artisan('lets:user:create', ['email' => 'cli@example.com', '--shop' => (string) $shop->id])
            ->expectsOutputToContain('https://cli.'.self::ROOT.'/admin/login')
            ->assertSuccessful();
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }
}
