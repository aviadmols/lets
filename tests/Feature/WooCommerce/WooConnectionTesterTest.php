<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use App\Services\WooCommerce\WooConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "Test connection" verdict: WooCommerce REST reachability (saved ck/cs valid) +
 * the LETS plugin token fingerprint (sha256 of its api_key) compared to lets_api_key_hash.
 */
class WooConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        WooClientFactory::clearFake();
        parent::tearDown();
    }

    private function wooShop(string $apiKey = 'api-key-1'): Shop
    {
        $shop = Shop::create(['name' => 'WC Store', 'status' => Shop::STATUS_ACTIVE]);
        $shop->forceFill([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'wc.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://wc.example.com',
                'consumer_key' => 'ck_x',
                'consumer_secret' => 'cs_x',
            ],
            'lets_api_key_hash' => hash('sha256', $apiKey),
        ])->save();

        return $shop->refresh();
    }

    public function test_no_credentials_reports_not_connected(): void
    {
        $shop = Shop::create(['name' => 'Empty', 'status' => Shop::STATUS_ACTIVE]);
        $shop->forceFill([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'empty.example.com',
        ])->save();
        Http::fake(['*/lets-payplus/v1/status' => Http::response('nope', 404)]);

        $result = app(WooConnectionTester::class)->test($shop->refresh());

        $this->assertFalse($result['ok']);
        $this->assertSame('warning', $result['level']);
    }

    public function test_working_wc_and_matching_plugin_is_success(): void
    {
        $shop = $this->wooShop('match-key');
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([['id' => 1]], 200),
            '*/lets-payplus/v1/status' => Http::response([
                'plugin_version' => '0.3.0',
                'key_hash' => hash('sha256', 'match-key'),
                'connected' => true,
            ], 200),
        ]);

        $result = app(WooConnectionTester::class)->test($shop);

        $this->assertTrue($result['ok']);
        $this->assertSame('success', $result['level']);
    }

    public function test_rejected_wc_keys_is_danger(): void
    {
        $shop = $this->wooShop();
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response('unauthorized', 401),
            '*/lets-payplus/v1/status' => Http::response(['plugin_version' => '0.3.0', 'key_hash' => $shop->lets_api_key_hash], 200),
        ]);

        $result = app(WooConnectionTester::class)->test($shop);

        $this->assertFalse($result['ok']);
        $this->assertSame('danger', $result['level']);
    }

    public function test_plugin_with_a_different_token_is_danger(): void
    {
        $shop = $this->wooShop('real-key');
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([], 200),
            '*/lets-payplus/v1/status' => Http::response([
                'plugin_version' => '0.3.0',
                'key_hash' => hash('sha256', 'SOME-OTHER-KEY'),
            ], 200),
        ]);

        $result = app(WooConnectionTester::class)->test($shop);

        $this->assertFalse($result['ok']);
        $this->assertSame('danger', $result['level']);
    }

    public function test_wc_ok_but_plugin_status_missing_is_a_soft_warning(): void
    {
        $shop = $this->wooShop();
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([], 200),
            '*/lets-payplus/v1/status' => Http::response('not found', 404),
        ]);

        $result = app(WooConnectionTester::class)->test($shop);

        $this->assertSame('warning', $result['level']);
    }
}
