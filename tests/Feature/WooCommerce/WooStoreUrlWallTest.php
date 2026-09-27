<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Brand\SafeSiteFetcher;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooPluginNotifier;
use App\Services\WooCommerce\WooStoreRefused;
use App\Services\WooCommerce\WooStoreUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicDnsSafeSiteFetcher;
use Tests\TestCase;

/**
 * The WooCommerce store URL is plugin-reported merchant input. It is rendered
 * into links on our origin and connected to by our workers, so:
 *   - only a canonical web URL is ever drawn into an href (stored rows from
 *     before the install wall included);
 *   - every outbound store request passes SafeSiteFetcher's address walls,
 *     including a well-formed name whose DNS answers a private address.
 */
final class WooStoreUrlWallTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const NOTIFY_PATH = '/wp-json/lets-payplus/v1/notify';

    // === The canonical shape ===

    public function test_only_plain_web_urls_are_canonical(): void
    {
        $this->assertSame('https://store.example.com', WooStoreUrl::canonical('https://store.example.com/'));
        $this->assertSame('https://store.example.com/shop', WooStoreUrl::canonical('https://store.example.com/shop/'));
        $this->assertSame('http://store.example.com', WooStoreUrl::canonical('http://store.example.com'));

        foreach ([
            'javascript:alert(1)//x',
            'https://javascript:alert(document.domain)',
            'https:///evil.com',
            'https://user@store.example.com',
            'https://store.example.com:6379',
            'https://store.example.com/?x=',
            'https://store.example.com/#frag',
            'https://10.0.0.5',
            'https://localhost',
            'https://store.example.com/"onmouseover=x',
            "https://store.example.com\n/x",
            'ftp://store.example.com',
        ] as $bad) {
            $this->assertNull(WooStoreUrl::canonical($bad), $bad.' must not be canonical');
        }
    }

    public function test_install_shape_is_https_on_the_minted_domain(): void
    {
        $this->assertSame('https://www.store.example.com', WooStoreUrl::forInstall('https://www.store.example.com', 'store.example.com'));
        $this->assertNull(WooStoreUrl::forInstall('http://store.example.com', 'store.example.com'));
        $this->assertNull(WooStoreUrl::forInstall('https://other.example.com', 'store.example.com'));
        $this->assertNull(WooStoreUrl::forInstall('https://store.example.com', ''));
    }

    // === Sinks: a legacy stored value never becomes an href ===

    public function test_the_deposit_return_page_never_links_a_script_url(): void
    {
        $shop = $this->wooShop('javascript:alert(document.domain)//x');

        $this->get('/woocommerce/deposit/return/'.$shop->wc_shop_token)
            ->assertOk()
            ->assertDontSee('javascript:', false);
    }

    public function test_the_deposit_return_page_links_a_real_store(): void
    {
        $shop = $this->wooShop('https://store.example.com');

        $this->get('/woocommerce/deposit/return/'.$shop->wc_shop_token)
            ->assertOk()
            ->assertSee('href="https://store.example.com"', false);
    }

    // === Outbound: the address walls ===

    public function test_the_rest_client_refuses_a_store_that_resolves_inside(): void
    {
        Http::fake();
        $this->app->bind(SafeSiteFetcher::class, static fn () => new PublicDnsSafeSiteFetcher([
            'store.example.com' => ['169.254.169.254'],
        ]));

        $client = new WooCommerceClient('https://store.example.com', 'ck', 'cs');

        try {
            $client->fetchProductById('1');
            $this->fail('A store resolving to the metadata service must be refused.');
        } catch (WooStoreRefused $e) {
            $this->assertInstanceOf(ConnectionException::class, $e);
            $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_the_rest_client_drops_a_smuggled_query_and_refuses_a_bad_base(): void
    {
        Http::fake();

        $this->expectException(WooStoreRefused::class);
        (new WooCommerceClient('https://store.example.com/?', 'ck', 'cs'))->fetchProductById('1');
    }

    public function test_the_rest_client_still_reaches_a_public_store(): void
    {
        Http::fake(['https://store.example.com/wp-json/wc/v3/products/1' => Http::response(['id' => 1], 200)]);

        $product = (new WooCommerceClient('https://store.example.com/', 'ck', 'cs'))->fetchProductById('1');

        $this->assertSame(1, $product['id']);
    }

    public function test_the_notifier_never_posts_to_a_private_address(): void
    {
        Http::fake();
        $this->app->bind(SafeSiteFetcher::class, static fn () => new PublicDnsSafeSiteFetcher([
            'store.example.com' => ['10.1.2.3'],
        ]));

        $shop = $this->wooShop('https://store.example.com', ['wc_webhook_secret' => 'whsecret']);

        app(WooPluginNotifier::class)->paymentFailed($shop, '1', '999');

        Http::assertNothingSent();
    }

    public function test_the_notifier_still_posts_to_a_public_store(): void
    {
        Http::fake(['*'.self::NOTIFY_PATH => Http::response(['ok' => true], 200)]);

        $shop = $this->wooShop('https://store.example.com', ['wc_webhook_secret' => 'whsecret']);

        app(WooPluginNotifier::class)->paymentFailed($shop, '1', '999');

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://store.example.com'.self::NOTIFY_PATH);
    }

    // === Helpers ===

    /** @param array<string, string> $extra */
    private function wooShop(string $baseUrl, array $extra = []): Shop
    {
        $shop = Shop::create(['name' => 'WC', 'status' => Shop::STATUS_ACTIVE]);
        $shop->forceFill([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
            'wc_shop_token' => 'tok'.uniqid(),
            'woocommerce_credentials' => ['base_url' => $baseUrl] + $extra,
        ])->save();

        return $shop->fresh();
    }
}
