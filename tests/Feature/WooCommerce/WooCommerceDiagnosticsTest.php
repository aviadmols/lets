<?php

namespace Tests\Feature\WooCommerce;

use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * W13 — merchant diagnostics for the plugin's Settings → LETS screen.
 *
 *   /diagnostics              → the ✅/❌ report (what is configured downstream of the HMAC).
 *   /diagnostics/payment-page → a REAL PayPlus hosted-page probe: returns the URL, or
 *                               PayPlus's verbatim rejection.
 *
 * Money law under test: the probe writes NO payment_ledger row (it is a probe, not a charge).
 * Auth law: unsigned → 401.
 */
final class WooCommerceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = '/api/woocommerce/diagnostics';
    private const PROBE = '/api/woocommerce/diagnostics/payment-page';

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_diagnostics_call_is_rejected_401(): void
    {
        $this->postJson(self::REPORT, [])->assertStatus(401);
        $this->postJson(self::PROBE, [])->assertStatus(401);
    }

    public function test_the_report_flags_a_missing_payment_page(): void
    {
        // Exactly shop 2's broken state: keys + terminal, but NO payment page.
        [, $key, $secret] = $this->shopWithPayplus('diag-nopage.example.com', [
            'api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't',
        ]);

        $response = $this->signedPost($key, $secret, self::REPORT, []);

        $response->assertOk();
        $this->assertTrue($response->json('payplus.has_terminal_uid'));
        $this->assertFalse($response->json('payplus.has_payment_page_uid'));
        $this->assertFalse($response->json('payplus.ready'));
        $this->assertSame('payplus_no_payment_page', $response->json('payplus.reason'));
    }

    public function test_the_report_is_ready_when_payplus_is_fully_configured(): void
    {
        [, $key, $secret] = $this->shopWithPayplus('diag-ok.example.com', [
            'api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ]);

        $response = $this->signedPost($key, $secret, self::REPORT, []);

        $response->assertOk();
        $this->assertTrue($response->json('payplus.ready'));
        $this->assertNull($response->json('payplus.reason'));
    }

    /** The report NEVER leaks a secret — only booleans about what is set. */
    public function test_the_report_never_returns_a_secret(): void
    {
        [, $key, $secret] = $this->shopWithPayplus('diag-secret.example.com', [
            'api_key' => 'super-secret-key', 'secret_key' => 'super-secret-secret',
            'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ]);

        $body = $this->signedPost($key, $secret, self::REPORT, [])->getContent();

        $this->assertStringNotContainsString('super-secret-key', (string) $body);
        $this->assertStringNotContainsString('super-secret-secret', (string) $body);
    }

    public function test_the_probe_returns_the_payplus_page_url_and_writes_no_ledger_row(): void
    {
        [, $key, $secret] = $this->shopWithPayplus('diag-probe.example.com', [
            'api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ]);
        $this->fakePage('https://pay.example/page/PROBE-1', success: true);

        $response = $this->signedPost($key, $secret, self::PROBE, []);

        $response->assertOk();
        $this->assertSame('https://pay.example/page/PROBE-1', $response->json('payment_page_url'));

        // Money law: a probe is not a charge.
        $this->assertSame(0, PaymentLedger::withoutGlobalScopes()->count());
    }

    /** The whole point of the tool: hand back PayPlus's OWN rejection text. */
    public function test_the_probe_surfaces_payplus_own_error(): void
    {
        [, $key, $secret] = $this->shopWithPayplus('diag-err.example.com', [
            'api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ]);
        $this->fakePage('', success: false, description: 'payment page uid is invalid');

        $response = $this->signedPost($key, $secret, self::PROBE, []);

        $response->assertStatus(502);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('payment page uid is invalid', $response->json('error'));
    }

    /** Without a payment page we don't even call PayPlus — we name the missing piece. */
    public function test_the_probe_short_circuits_when_no_payment_page_is_configured(): void
    {
        [, $key, $secret] = $this->shopWithPayplus('diag-short.example.com', [
            'api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't',
        ]);

        $response = $this->signedPost($key, $secret, self::PROBE, []);

        $response->assertStatus(422);
        $this->assertSame('payplus_no_payment_page', $response->json('error'));
    }

    // === Helpers ===

    /**
     * @param  array<string,mixed>  $payplus
     * @return array{0:Shop,1:string,2:string}
     */
    private function shopWithPayplus(string $domain, array $payplus): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = $payplus;
        $shop->save();

        // The report pings the WC REST API (WooConnectionTester) — keep it off the network.
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([], 200),
            '*/lets-payplus/v1/status' => Http::response('', 404),
        ]);

        [$key, $secret] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $key, $secret];
    }

    private function fakePage(string $url, bool $success, string $description = ''): void
    {
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($url, $success, $description) implements PayPlusGatewayInterface
        {
            public function __construct(private string $url, private bool $success, private string $description) {}

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
                if (! $this->success) {
                    return GatewayResult::fromResponse([
                        'results' => ['status' => 'error', 'code' => 1, 'description' => $this->description],
                    ]);
                }

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['payment_page_link' => $this->url],
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
