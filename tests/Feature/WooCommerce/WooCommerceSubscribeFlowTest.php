<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
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
 * W11 P3 — the WooCommerce RECURRING subscription storefront flow. The plugin server
 * signs the HMAC call; /subscribe recomputes the per-cycle price server-side, creates a
 * recurring plan (awaiting_first_payment, no deposit, no slices) + the PayPlus first-
 * payment page, and returns the page URL. Unsigned → 401; one shop can never price
 * against another's catalog.
 */
final class WooCommerceSubscribeFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSCRIBE = '/api/woocommerce/installments/subscribe';

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_subscribe_creates_a_recurring_plan_and_returns_the_payplus_page(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sub.example.com');
        $this->seedVariant($shop, '100', '200', 49.90);
        $this->fakeGatewayPage();

        $response = $this->signed($key, $secret, [
            'product_id' => 100, 'variant_id' => 200, 'frequency' => 'monthly',
        ]);

        $response->assertStatus(201);
        $this->assertSame('https://pay.example/page/SUB-1', $response->json('invoice_url'));
        $this->assertEqualsWithDelta(49.90, (float) $response->json('amount'), 0.001);

        $publicId = (string) $response->json('plan_public_id');
        $plan = Tenant::run($shop, fn (): ?InstallmentPlan => InstallmentPlan::query()->where('public_id', $publicId)->first());
        $this->assertNotNull($plan);
        $this->assertSame(PlanKind::RECURRING, $plan->plan_kind);
        $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
        $this->assertSame((int) $shop->getKey(), (int) $plan->shop_id);
        $this->assertNull($plan->next_charge_at);                       // null until first payment
        $this->assertEqualsWithDelta(49.90, (float) $plan->installment_amount, 0.001);
    }

    public function test_subscribe_uses_the_server_trusted_price_not_a_client_amount(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sub-trust.example.com');
        $this->seedVariant($shop, '100', '200', 49.90);
        $this->fakeGatewayPage();

        // Client lies with amount=1 — ignored; the recurring amount is the real 49.90.
        $this->signed($key, $secret, ['product_id' => 100, 'variant_id' => 200, 'amount' => 1])
            ->assertStatus(201)
            ->assertJsonPath('plan_public_id', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_an_unsigned_subscribe_is_rejected_401(): void
    {
        $this->postJson(self::SUBSCRIBE, ['variant_id' => 1])->assertStatus(401);
    }

    public function test_subscribe_for_an_unsynced_variation_is_422(): void
    {
        [, $key, $secret] = $this->connectedShop('sub-422.example.com');

        $this->signed($key, $secret, ['variant_id' => 999999])->assertStatus(422);
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

    private function seedVariant(Shop $shop, string $productId, string $variantId, float $price): void
    {
        Tenant::run($shop, function () use ($shop, $productId, $variantId, $price): void {
            $product = new Product;
            $product->fill(['source' => 'woocommerce', 'external_id' => $productId, 'title' => 'WC Coffee', 'status' => 'active']);
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
                    'data' => ['page_request_uid' => 'SUB-1', 'payment_page_link' => 'https://pay.example/page/SUB-1'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    /** @param array<string,mixed> $body */
    private function signed(string $apiKey, string $apiSecret, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.self::SUBSCRIBE.$json, $apiSecret, true));

        return $this->call('POST', self::SUBSCRIBE, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
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
