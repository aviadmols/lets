<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * W11 P4 — the full PayPlus gateway ("mode B"). /gateway/session returns a PayPlus page
 * URL for the order total (the plugin's process_payment redirects there); the gateway
 * callback marks the WC order paid via WC REST. The shop is the HMAC-verified shop;
 * unsigned → 401; the callback resolves the shop from the opaque token segment.
 */
final class WooCommerceGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = '/api/woocommerce/gateway/session';

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_session_returns_a_payplus_redirect_url_for_the_order_total(): void
    {
        [, $key, $secret] = $this->connectedShop('gw.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-1');

        $response = $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4242', 'amount' => 199.90, 'currency' => 'ILS', 'return_url' => 'https://gw.example.com/thanks',
        ]);

        $response->assertOk();
        $this->assertSame('https://pay.example/page/GW-1', $response->json('redirect_url'));
    }

    public function test_session_rejects_a_zero_amount_order(): void
    {
        [, $key, $secret] = $this->connectedShop('gw-zero.example.com');

        $this->signedPost($key, $secret, self::SESSION, ['order_id' => '1', 'amount' => 0])
            ->assertStatus(422);
    }

    public function test_an_unsigned_session_is_rejected_401(): void
    {
        $this->postJson(self::SESSION, ['order_id' => '1', 'amount' => 10])->assertStatus(401);
    }

    public function test_the_gateway_callback_marks_the_wc_order_paid(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders/4242' => Http::response(['id' => 4242, 'status' => 'processing'], 200)]);
        [$shop] = $this->connectedShop('gw-cb.example.com');
        $token = (string) $shop->wc_shop_token;

        $response = $this->postJson('/woocommerce/gateway/callback/'.$token, [
            'transaction' => ['more_info' => 'gw:4242', 'status_code' => '000'],
        ]);

        $response->assertOk()->assertJsonPath('paid', true);

        Http::assertSent(function (HttpRequest $req) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders/4242')
                && $req->method() === 'PUT'
                && ($b['status'] ?? null) === 'processing'
                && ($b['set_paid'] ?? null) === true;
        });
    }

    public function test_a_non_gateway_callback_does_not_mark_anything_paid(): void
    {
        Http::fake();
        [$shop] = $this->connectedShop('gw-cb2.example.com');

        // more_info without the gw: prefix (e.g. a deposit plan id) is ignored here.
        $this->postJson('/woocommerce/gateway/callback/'.$shop->wc_shop_token, [
            'transaction' => ['more_info' => 'PUB-PLAN', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('paid', false);

        Http::assertNothingSent();
    }

    public function test_an_unknown_gateway_callback_token_is_404(): void
    {
        $this->postJson('/woocommerce/gateway/callback/not-a-token', [
            'transaction' => ['more_info' => 'gw:1', 'status_code' => '000'],
        ])->assertStatus(404);
    }

    // === Helpers ===

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        [$key, $secret] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $key, $secret];
    }

    private function fakeGatewayPage(string $url): void
    {
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($url) implements PayPlusGatewayInterface
        {
            public function __construct(private string $url) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['page_request_uid' => 'GW-1', 'payment_page_link' => $this->url],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    /** @param array<string,mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /** @return array{0:string,1:string} */
    private function keys(string $token): array
    {
        $json = (string) base64_decode(strtr($token, '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
