<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * W11 P2 — the WooCommerce deposit + installments SELLING flow. The plugin server signs
 * HMAC calls (the shopper's browser never holds the api_secret). /quote returns a
 * server-computed schedule; /start creates a tenant-scoped awaiting_first_payment plan +
 * the PayPlus hosted page URL. The price is resolved server-side from the synced WC
 * catalog (never the client's amount); an unsigned request is rejected 401; one shop can
 * never price/start against another's catalog.
 */
final class WooCommerceInstallmentsFlowTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = '/api/woocommerce/installments/quote';
    private const START = '/api/woocommerce/installments/start';

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_quote_returns_a_server_computed_schedule_for_a_synced_variation(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('quote.example.com');
        $this->seedVariant($shop, productId: '100', variantId: '200', price: 400.0);

        $response = $this->signed($key, $secret, self::QUOTE, [
            'product_id' => 200, 'variant_id' => 200, 'deposit_percent' => 25, 'installments' => 3, 'frequency' => 'monthly',
        ]);

        $response->assertOk();
        // Deposit = 25% of 400 = 100; financed 300 over 3 = 100 each.
        $this->assertEqualsWithDelta(100.0, (float) $response->json('quote.deposit_amount'), 0.001);
        $this->assertSame(3, $response->json('quote.installments'));
        $this->assertCount(3, $response->json('quote.schedule'));
    }

    public function test_quote_for_an_unsynced_variation_is_422(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('quote2.example.com');

        $this->signed($key, $secret, self::QUOTE, ['variant_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('error', 'variant_not_priceable');
    }

    public function test_start_creates_a_tenant_scoped_plan_and_returns_the_payplus_page_url(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('start.example.com');
        $this->seedVariant($shop, productId: '100', variantId: '200', price: 400.0);
        $this->fakeGatewayPage();

        $response = $this->signed($key, $secret, self::START, [
            'product_id' => 100, 'variant_id' => 200, 'deposit_percent' => 25, 'installments' => 3, 'frequency' => 'monthly',
        ]);

        $response->assertStatus(201);
        $this->assertSame('https://pay.example/page/PR-1', $response->json('invoice_url'));
        $this->assertEqualsWithDelta(100.0, (float) $response->json('deposit_amount'), 0.001);
        $publicId = (string) $response->json('plan_public_id');
        $this->assertNotSame('', $publicId);

        // The plan exists, tenant-stamped + awaiting_first_payment, with the page linkage.
        $plan = Tenant::run($shop, fn (): ?InstallmentPlan => InstallmentPlan::query()->where('public_id', $publicId)->first());
        $this->assertNotNull($plan);
        $this->assertSame((int) $shop->getKey(), (int) $plan->shop_id);
        $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
        $this->assertSame('https://pay.example/page/PR-1', data_get($plan->meta, 'deposit_invoice_url'));
    }

    public function test_start_uses_the_server_trusted_price_not_a_client_supplied_amount(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('trust.example.com');
        $this->seedVariant($shop, productId: '100', variantId: '200', price: 400.0);
        $this->fakeGatewayPage();

        // Client lies with a tiny 'total'/'amount' — ignored; deposit is 25% of the real 400.
        $response = $this->signed($key, $secret, self::START, [
            'product_id' => 100, 'variant_id' => 200, 'deposit_percent' => 25, 'installments' => 3,
            'amount' => 1, 'total' => 1, 'deposit_amount' => 1,
        ]);

        $response->assertStatus(201);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('deposit_amount'), 0.001);
    }

    public function test_an_unsigned_request_is_rejected_401(): void
    {
        $this->postJson(self::QUOTE, ['variant_id' => 1])->assertStatus(401);
        $this->postJson(self::START, ['variant_id' => 1])->assertStatus(401);
    }

    public function test_a_shop_can_never_price_against_another_shops_catalog(): void
    {
        [$shopA] = $this->connectedShop('iso-a.example.com');
        $this->seedVariant($shopA, productId: '100', variantId: '200', price: 400.0);

        [$shopB, $keyB, $secretB] = $this->connectedShop('iso-b.example.com');

        // Shop B signs a quote for shop A's variation id — B has no such variant → 422.
        $this->signed($keyB, $secretB, self::QUOTE, ['variant_id' => 200])
            ->assertStatus(422);
    }

    // === Helpers ===

    /** @return array{0:Shop,1:string,2:string} [shop, api_key, api_secret] */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];

        // Give the shop WC REST creds + a token + PayPlus creds (the gateway is faked).
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        [$key, $secret] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $key, $secret];
    }

    private function seedVariant(Shop $shop, string $productId, string $variantId, float $price): void
    {
        Tenant::run($shop, function () use ($shop, $productId, $variantId, $price): void {
            $product = new Product;
            $product->fill(['source' => 'woocommerce', 'external_id' => $productId, 'title' => 'WC Sofa', 'status' => 'active']);
            $product->forceFill(['shop_id' => (int) $shop->getKey()])->save();

            $variant = new ProductVariant;
            $variant->fill(['product_id' => $product->getKey(), 'external_variant_id' => $variantId, 'price' => $price, 'position' => 0]);
            $variant->forceFill(['shop_id' => (int) $shop->getKey()])->save();
        });
    }

    private function fakeGatewayPage(): void
    {
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
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
                    'data' => ['page_request_uid' => 'PR-1', 'payment_page_link' => 'https://pay.example/page/PR-1'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    /** @param array<string,mixed> $body */
    private function signed(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /** @return array{0:string,1:string} [api_key, api_secret] */
    private function keys(string $token): array
    {
        $json = (string) base64_decode(strtr($token, '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
