<?php

namespace Tests\Feature\WooCommerce;

use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
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
 * W17 Part B — cart-based subscriptions end to end. /gateway/session with subscription_items creates
 * an awaiting_first_payment recurring plan per the merchant's TEMPLATE (cadence/discount server-
 * resolved, create_token forced), and the gateway callback (finalizer) activates it on paid: token
 * vaulted to the plan, first cycle recorded at the PER-CYCLE amount (not the cart total), consent
 * CONTEXT_RECURRING, next_charge_at set, status active. Idempotent + money-safe + tenant-safe.
 */
final class WooCommerceCartSubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = '/api/woocommerce/gateway/session';

    /** @var array<int, array<string, mixed>> captured generateLink payloads */
    public array $gatewayPayloads = [];

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_session_creates_a_recurring_plan_from_the_template_and_forces_create_token(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-sub.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0, discountPercent: 10));
        $this->fakeGateway();

        $response = $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8400', 'amount' => 90.0, 'currency' => 'ILS',
            'return_url' => 'https://cart-sub.example.com/thanks',
            'customer_id' => '55', 'first_name' => 'Dana', 'email' => 'dana@example.com',
            'subscription_items' => [
                ['product_id' => '501', 'variant_id' => '501', 'quantity' => 1],
            ],
        ])->assertOk();

        $planId = (string) $response->json('subscription_plan_ids.0');
        $this->assertNotSame('', $planId);

        // create_token forced ON for a subscription first payment (so the engine gets a token).
        $this->assertTrue((bool) ($this->gatewayPayloads[0]['create_token'] ?? false));

        Tenant::run($shop, function () use ($planId): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();

            $this->assertSame(PlanKind::RECURRING, $plan->plan_kind);
            $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
            $this->assertSame('monthly', $plan->billing_frequency->value);
            $this->assertNull($plan->next_charge_at);
            // 100 − 10% = 90 per cycle, from the TEMPLATE (not the request amount).
            $this->assertEqualsWithDelta(90.0, (float) $plan->installment_amount, 0.001);
        });
    }

    public function test_the_callback_activates_the_subscription_plan_at_the_per_cycle_amount(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-activate.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0, discountPercent: 10));
        $this->fakeGateway();

        // 1) Session creates the awaiting plan.
        $planId = (string) $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8500', 'amount' => 90.0, 'currency' => 'ILS',
            'customer_id' => '55',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->json('subscription_plan_ids.0');

        // 2) PayPlus captures the first cycle → its callback marks the order paid. The WC order the
        //    finalizer fetches carries the plan ids as meta (what the plugin persisted) + the token.
        Http::fake(['*/wp-json/wc/v3/orders/8500' => Http::response([
            'id' => 8500, 'status' => 'processing', 'customer_id' => 55, 'billing' => ['email' => 'dana@example.com'],
            'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]],
        ], 200)]);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, [
            'transaction' => [
                'more_info' => 'gw:8500', 'status_code' => '000',
                'token_uid' => 'tok-sub-1', 'customer_uid' => 'pp-c-1', 'four_digits' => '4242',
            ],
        ])->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function () use ($planId, $shop): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();

            $this->assertSame(PlanStatus::ACTIVE, $plan->status);
            $this->assertNotNull($plan->next_charge_at, 'next cycle scheduled');
            $this->assertNotNull($plan->payment_method_id, 'token vaulted to the plan');

            // The first cycle is recorded at the PER-CYCLE amount (90), NOT the cart total.
            $ledger = PaymentLedger::query()->where('plan_id', $plan->getKey())
                ->where('status', LedgerStatus::SUCCEEDED->value)->sole();
            $this->assertEqualsWithDelta(90.0, (float) $ledger->amount, 0.001);

            // Recurring consent (the charge engine's gate looks it up by this context).
            $consent = CustomerConsent::query()->where('plan_id', $plan->getKey())->sole();
            $this->assertSame(CustomerConsent::CONTEXT_RECURRING, $consent->consent_context);
        });
    }

    public function test_the_callback_activates_the_plan_only_once_on_replay(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-replay.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0));
        $this->fakeGateway();

        $planId = (string) $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8600', 'amount' => 100.0, 'currency' => 'ILS', 'customer_id' => '9',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->json('subscription_plan_ids.0');

        Http::fake(['*/wp-json/wc/v3/orders/8600' => Http::response([
            'id' => 8600, 'status' => 'processing', 'customer_id' => 9, 'billing' => ['email' => 'g@example.com'],
            'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]],
        ], 200)]);

        $body = ['transaction' => ['more_info' => 'gw:8600', 'status_code' => '000', 'token_uid' => 'tok-r']];
        $url = '/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token;

        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk(); // replay

        Tenant::run($shop, function () use ($planId): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();
            $this->assertSame(PlanStatus::ACTIVE, $plan->status);
            // Exactly ONE succeeded first-cycle ledger row despite two callbacks.
            $this->assertSame(1, PaymentLedger::query()->where('plan_id', $plan->getKey())
                ->where('status', LedgerStatus::SUCCEEDED->value)->count());
        });
    }

    public function test_a_plain_order_without_subscription_items_creates_no_plan_and_does_not_force_token(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-plain.example.com');
        $this->fakeGateway();

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8700', 'amount' => 50.0, 'currency' => 'ILS',
        ])->assertOk()->assertJsonPath('subscription_plan_ids', []);

        // No subscription items → we do NOT force create_token (merchant setting governs it).
        $this->assertArrayNotHasKey('create_token', $this->gatewayPayloads[0]);
        $this->assertSame(0, InstallmentPlan::query()->count());
    }

    // === Helpers ===

    private function fakeGateway(): void
    {
        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private WooCommerceCartSubscriptionFlowTest $test) {}

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
                    'data' => ['page_request_uid' => 'PRU', 'payment_page_link' => 'https://pay.example/p/PRU'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    private function makeSubscriptionProduct(Shop $shop, string $externalId, float $price, int $discountPercent = 0): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $shop->id, 'source' => Product::SOURCE_WOOCOMMERCE, 'external_id' => $externalId,
            'title' => 'Product '.$externalId, 'status' => Product::STATUS_ACTIVE, 'online_store_status' => 'published',
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'external_variant_id' => $externalId,
            'title' => '', 'sku' => 'SKU-'.$externalId, 'price' => $price, 'position' => 0,
        ])->save();

        $plan = new ProductSubscriptionPlan;
        $plan->fill([
            'product_id' => $product->id, 'product_variant_id' => null,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION, 'plan_kind' => 'recurring',
            'plan_name' => 'Monthly', 'billing_frequency' => 'monthly', 'interval_count' => 1,
            'discount_type' => $discountPercent > 0 ? ProductSubscriptionPlan::DISCOUNT_PERCENT : ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => $discountPercent,
            'channels' => [ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET], 'position' => 0,
        ]);
        $plan->forceFill(['shop_id' => $shop->id, 'status' => ProductSubscriptionPlan::STATUS_ACTIVE])->save();
    }

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

        [$k, $s] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $k, $s];
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
        $data = (array) json_decode((string) base64_decode(strtr($token, '-_', '+/')), true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
