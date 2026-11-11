<?php

namespace Tests\Feature\WooCommerce;

use App\Filament\Pages\ManageWordPressPlugin;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → WordPress plugin: a WooCommerce merchant downloads the plugin
 * themselves and sees whether their store runs the newest version.
 */
final class WordPressPluginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_newest_version_is_read_from_the_shipped_plugin(): void
    {
        $header = (string) file_get_contents(base_path(ManageWordPressPlugin::PLUGIN_MAIN_FILE));
        preg_match("/define\('LETS_PAYPLUS_VERSION', '([^']+)'\)/", $header, $m);

        $this->assertSame($m[1], ManageWordPressPlugin::latestVersion());
    }

    public function test_a_store_on_an_old_version_is_told_to_update(): void
    {
        $shop = $this->shop(Shop::PLATFORM_WOOCOMMERCE, 'plugin-old.example.com');
        Http::fake(['*/wp-json/lets-payplus/v1/status' => Http::response(['plugin_version' => '0.10.0'])]);

        Tenant::run($shop, function () use ($shop): void {
            $this->actingAs(User::factory()->forShop($shop)->create());

            $this->assertTrue(ManageWordPressPlugin::canAccess());

            Livewire::test(ManageWordPressPlugin::class)
                ->assertSee(route('woocommerce.plugin.download'))
                ->call('checkInstalled')
                ->assertSet('installedVersion', '0.10.0')
                ->assertSet('state', ManageWordPressPlugin::STATE_OUTDATED);
        });
    }

    public function test_an_unreachable_store_is_unknown_not_outdated(): void
    {
        $shop = $this->shop(Shop::PLATFORM_WOOCOMMERCE, 'plugin-down.example.com');
        Http::fake(['*' => Http::response('', 500)]);

        Tenant::run($shop, function () use ($shop): void {
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ManageWordPressPlugin::class)
                ->call('checkInstalled')
                ->assertSet('installedVersion', null)
                ->assertSet('state', ManageWordPressPlugin::STATE_UNKNOWN);
        });
    }

    public function test_a_shopify_shop_has_no_plugin_page(): void
    {
        $shop = $this->shop(Shop::PLATFORM_SHOPIFY, 'plugin-shopify.myshopify.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->actingAs(User::factory()->forShop($shop)->create());

            $this->assertFalse(ManageWordPressPlugin::canAccess());
        });
    }

    private function shop(string $platform, string $domain): Shop
    {
        return Shop::create([
            $platform === Shop::PLATFORM_SHOPIFY ? 'shopify_domain' : 'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => $platform,
        ]);
    }
}
