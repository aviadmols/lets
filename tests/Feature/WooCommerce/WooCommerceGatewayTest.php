<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPaymentMethod;
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

    /** @var array<int, array<string, mixed>> captured generateLink payloads (W17) */
    public array $gatewayPayloads = [];

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

    public function test_session_sends_immediate_capture_charge_method_and_returns_page_request_uid(): void
    {
        // W17: the gateway must send charge_method=1 (capture), not 0 (verify-only — the bug),
        // and hand back the page_request_uid so verify-on-return can confirm the order.
        [, $key, $secret] = $this->connectedShop('gw-charge.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-1');

        $response = $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4243', 'amount' => 50.0, 'currency' => 'ILS', 'return_url' => 'https://gw-charge.example.com/thanks',
        ]);

        $response->assertOk()->assertJsonPath('page_request_uid', 'GW-1');
        $this->assertSame(1, $this->gatewayPayloads[0]['charge_method']);
    }

    public function test_session_charge_method_honours_the_configured_value(): void
    {
        config()->set('woocommerce.charge_method', 2);
        [, $key, $secret] = $this->connectedShop('gw-charge2.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-2');

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4244', 'amount' => 50.0, 'currency' => 'ILS',
        ])->assertOk();

        $this->assertSame(2, $this->gatewayPayloads[0]['charge_method']);
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

    /**
     * THE gap that made the one-click upsell "green and dead": a plain checkout vaulted NOTHING,
     * so the thank-you page could never charge a saved card. The callback must now vault the
     * reusable token — keyed by the SAME customer ref the thank-you widget sends (WC customer id,
     * else the billing email), or the two can never be matched.
     */
    public function test_the_gateway_callback_vaults_the_reusable_payplus_token(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders/5150' => Http::response([
            'id' => 5150, 'status' => 'processing',
            'customer_id' => 77, 'billing' => ['email' => 'buyer@example.com'],
        ], 200)]);
        [$shop] = $this->connectedShop('gw-vault.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, [
            'transaction' => [
                'more_info' => 'gw:5150', 'status_code' => '000',
                'token_uid' => 'tok-live-1', 'customer_uid' => 'pp-cust-9',
                'four_digits' => '4242', 'brand_name' => 'Visa',
            ],
        ])->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function (): void {
            $method = InstallmentPaymentMethod::sole();

            $this->assertSame('tok-live-1', $method->payplus_card_token_uid);
            $this->assertSame('pp-cust-9', $method->payplus_customer_uid);
            $this->assertSame('4242', $method->card_last_four);
            $this->assertSame(InstallmentPaymentMethod::STATUS_ACTIVE, $method->status);
            // The registered customer's WC id — exactly what class-lets-thankyou.php sends.
            $this->assertSame('77', $method->shopify_customer_id);
        });
    }

    /** A guest has no WC customer id — the billing email is the ref, on BOTH sides. */
    public function test_a_guest_checkout_vaults_the_token_against_the_billing_email(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders/5151' => Http::response([
            'id' => 5151, 'status' => 'processing',
            'customer_id' => 0, 'billing' => ['email' => 'guest@example.com'],
        ], 200)]);
        [$shop] = $this->connectedShop('gw-guest.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, [
            'transaction' => ['more_info' => 'gw:5151', 'status_code' => '000', 'token_uid' => 'tok-guest'],
        ])->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertSame('guest@example.com', InstallmentPaymentMethod::sole()->shopify_customer_id);
        });
    }

    /** A replayed callback must not vault the same card twice. */
    public function test_a_replayed_callback_vaults_the_card_only_once(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders/5152' => Http::response([
            'id' => 5152, 'status' => 'processing', 'customer_id' => 5, 'billing' => ['email' => 'r@e.com'],
        ], 200)]);
        [$shop] = $this->connectedShop('gw-replay.example.com');
        $body = ['transaction' => ['more_info' => 'gw:5152', 'status_code' => '000', 'token_uid' => 'tok-once']];

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $body)->assertOk();
        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $body)->assertOk();

        Tenant::run($shop, fn () => $this->assertSame(1, InstallmentPaymentMethod::count()));
    }

    /** No token in the callback (create_token off) → the order is STILL paid; nothing vaulted. */
    public function test_a_callback_without_a_token_still_pays_the_order(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders/5153' => Http::response(['id' => 5153, 'customer_id' => 1], 200)]);
        [$shop] = $this->connectedShop('gw-notok.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, [
            'transaction' => ['more_info' => 'gw:5153', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, fn () => $this->assertSame(0, InstallmentPaymentMethod::count()));
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

    /**
     * VERIFY-ON-RETURN (the fix for orders stuck "pending"): the plugin confirms the payment
     * from the thank-you page. When PayPlus's IPN says approved, we mark the WC order paid AND
     * vault the token — exactly as the push callback would, via the shared finalizer.
     */
    public function test_verify_on_return_marks_the_order_paid_and_vaults_the_token(): void
    {
        Http::fake([
            '*/PaymentPages/ipn' => Http::response([
                'results' => ['status' => 'success'],
                'data' => ['transaction' => [
                    'uid' => 'txn-v1', 'status_code' => '000', 'amount' => '1.00', 'approval_number' => 'APP123',
                    'four_digits' => '4242', 'token_uid' => 'tok-verify', 'customer_uid' => 'cu-9',
                ]],
            ], 200),
            '*/wp-json/wc/v3/orders/8080' => Http::response([
                'id' => 8080, 'status' => 'processing', 'customer_id' => 42, 'billing' => ['email' => 'b@e.com'],
            ], 200),
            '*/wp-json/wc/v3/orders/8080/notes' => Http::response(['id' => 1, 'note' => 'ok'], 201),
        ]);
        [$shop, $key, $secret] = $this->connectedShop('gw-verify.example.com');

        $this->signedPost($key, $secret, '/api/woocommerce/gateway/verify', [
            'order_id' => '8080', 'page_request_uid' => 'PRU-1',
        ])->assertOk()->assertJsonPath('paid', true);

        // W18: the IPN lookup MUST send the uid under PayPlus's `payment_request_uid` key (not the
        // generateLink `page_request_uid` name) — the bug that made verify-on-return always error.
        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/PaymentPages/ipn')
            && ($req->data()['payment_request_uid'] ?? null) === 'PRU-1');

        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/wp-json/wc/v3/orders/8080')
            && $req->method() === 'PUT' && ($req->data()['set_paid'] ?? null) === true);

        // W18: a merchant-visible PayPlus confirmation NOTE is recorded, carrying the transaction id.
        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/wp-json/wc/v3/orders/8080/notes')
            && $req->method() === 'POST' && str_contains((string) ($req->data()['note'] ?? ''), 'txn-v1'));

        Tenant::run($shop, function (): void {
            $method = InstallmentPaymentMethod::sole();
            $this->assertSame('tok-verify', $method->payplus_card_token_uid);
            $this->assertSame('42', $method->shopify_customer_id);
        });
    }

    /** A not-approved IPN must NOT mark paid or vault anything. */
    public function test_verify_on_return_does_nothing_when_not_approved(): void
    {
        Http::fake(['*/PaymentPages/ipn' => Http::response([
            'results' => ['status' => 'error'],
            'data' => ['transaction' => ['status_code' => 'declined']],
        ], 200)]);
        [$shop, $key, $secret] = $this->connectedShop('gw-verify-no.example.com');

        $this->signedPost($key, $secret, '/api/woocommerce/gateway/verify', [
            'order_id' => '8081', 'page_request_uid' => 'PRU-2',
        ])->assertOk()->assertJsonPath('paid', false);

        Http::assertNotSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/wp-json/wc/v3/orders/'));
        Tenant::run($shop, fn () => $this->assertSame(0, InstallmentPaymentMethod::count()));
    }

    public function test_an_unsigned_verify_is_rejected_401(): void
    {
        $this->postJson('/api/woocommerce/gateway/verify', ['order_id' => '1', 'page_request_uid' => 'x'])
            ->assertStatus(401);
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
        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($url, $test) implements PayPlusGatewayInterface
        {
            public function __construct(private string $url, private WooCommerceGatewayTest $test) {}

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
                $this->test->gatewayPayloads[] = $payload;

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
