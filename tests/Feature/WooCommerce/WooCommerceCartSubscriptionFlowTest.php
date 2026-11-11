<?php

namespace Tests\Feature\WooCommerce;

use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantCheckoutSettings;
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
use App\Services\PayPlus\PayPlusPageOptions;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesPayPlusIpn;
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
    use FakesPayPlusIpn;
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

    /**
     * A subscription cart asked to pay with PayPal still gets the CARD token page:
     * PayPal would pay the first cycle and leave nothing to bill the second with.
     * (The plugin never offers PayPal on such a cart; this is the server's wall.)
     */
    public function test_a_subscription_cart_never_gets_the_paypal_page(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-sub-pp.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0, discountPercent: 10));
        $this->fakeGateway();

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8401', 'amount' => 90.0, 'currency' => 'ILS', 'charge_method' => 'paypal',
            'customer_id' => '55', 'email' => 'dana@example.com',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->assertOk();

        $this->assertSame(['credit-card'], $this->gatewayPayloads[0]['allowed_charge_methods']);
        $this->assertTrue((bool) $this->gatewayPayloads[0]['create_token']);
        $this->assertNotSame('paypal', $this->gatewayPayloads[0]['charge_default'] ?? null);
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
        Http::fake([
            // PayPlus's own record of the page: approved for the order total, with the card.
            ...$this->payplusIpn('gw:8500', [
                'amount' => '90.00', 'token_uid' => 'tok-sub-1', 'customer_uid' => 'pp-c-1', 'four_digits' => '4242',
            ]),
            '*/wp-json/wc/v3/orders/8500' => Http::response([
                'id' => 8500, 'status' => 'processing', 'total' => '90.00', 'customer_id' => 55, 'billing' => ['email' => 'dana@example.com'],
                'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]],
            ], 200),
        ]);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:8500', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function () use ($planId): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();

            $this->assertSame(PlanStatus::ACTIVE, $plan->status);
            $this->assertNotNull($plan->next_charge_at, 'next cycle scheduled');
            $this->assertNotNull($plan->payment_method_id, 'token vaulted to the plan');

            // The first cycle is recorded at the PER-CYCLE amount (90), NOT the cart total.
            $ledger = PaymentLedger::query()->where('plan_id', $plan->getKey())
                ->where('status', LedgerStatus::SUCCEEDED->value)->sole();
            $this->assertEqualsWithDelta(90.0, (float) $ledger->amount, 0.001);

            // …and it is labelled as what it IS. A subscription's first payment is a
            // recurring cycle, not a deposit against a balance — the document side
            // has always invoiced it as recurring, so a ledger row reading "Deposit"
            // made the Payments and Invoices screens describe one charge two ways.
            $this->assertSame(PaymentLedger::CONTEXT_RECURRING, $ledger->charge_context);

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

        Http::fake([
            ...$this->payplusIpn('gw:8600', ['amount' => '100.00', 'token_uid' => 'tok-r']),
            '*/wp-json/wc/v3/orders/8600' => Http::response([
                'id' => 8600, 'status' => 'processing', 'total' => '100.00', 'customer_id' => 9, 'billing' => ['email' => 'g@example.com'],
                'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]],
            ], 200),
        ]);

        $body = $this->callbackFor(['more_info' => 'gw:8600', 'status_code' => '000']);
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

    // === A free first cycle (100% first-payment coupon → a ₪0 order) ===

    public function test_a_free_first_cycle_opens_a_check_only_card_page(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('free-first.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0, discountPercent: 10));
        $this->fakeGateway();

        $planId = (string) $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8700', 'amount' => 0, 'currency' => 'ILS', 'customer_id' => '55',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->assertOk()->json('subscription_plan_ids.0');

        $page = $this->gatewayPayloads[0];
        // PayPlus opens no page for ₪0: the card-update page's symbolic amount, CHECK-ONLY.
        $this->assertEqualsWithDelta(1.0, (float) $page['amount'], 0.001);
        $this->assertSame(0, $page['charge_method']);
        $this->assertTrue((bool) $page['create_token']);
        $this->assertSame(__('storefront.free_first_cycle.item'), $page['product_name']);

        // The plan is priced from the template as always — the coupon never reaches it.
        Tenant::run($shop, function () use ($planId): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();
            $this->assertEqualsWithDelta(90.0, (float) $plan->installment_amount, 0.001);
        });
    }

    public function test_a_free_order_without_a_subscription_is_still_refused(): void
    {
        [, $key, $secret] = $this->connectedShop('free-plain.example.com');
        $this->fakeGateway();

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8701', 'amount' => 0, 'currency' => 'ILS',
        ])->assertStatus(422)->assertJsonPath('error', 'invalid_order');

        $this->assertSame([], $this->gatewayPayloads);
    }

    public function test_a_free_first_cycle_activates_at_zero_and_bills_the_next_cycle_at_the_full_price(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('free-activate.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0, discountPercent: 10));
        $this->fakeGateway();

        $planId = (string) $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8710', 'amount' => 0, 'currency' => 'ILS', 'customer_id' => '55',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->json('subscription_plan_ids.0');

        Http::fake([
            // The card CHECK: approved, the symbolic ₪1, and the saved card.
            ...$this->payplusIpn('gw:8710', [
                'amount' => '1.00', 'token_uid' => 'tok-free-1', 'customer_uid' => 'pp-c-1', 'four_digits' => '4242',
            ]),
            '*/wp-json/wc/v3/orders/8710' => Http::response([
                'id' => 8710, 'status' => 'pending', 'total' => '0.00', 'currency' => 'ILS', 'customer_id' => 55,
                'billing' => ['email' => 'dana@example.com'],
                'coupon_lines' => [['code' => 'month1', 'discount' => '90.00']],
                'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]],
            ], 200),
        ]);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:8710', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function () use ($planId): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();

            $this->assertSame(PlanStatus::ACTIVE, $plan->status);
            $this->assertNotNull($plan->payment_method_id, 'the card is saved for the next cycle');
            $this->assertTrue($plan->next_charge_at->isFuture(), 'the next cycle is scheduled');
            // The next cycle bills at the subscription price, not at the coupon's ₪0.
            $this->assertEqualsWithDelta(90.0, (float) $plan->installment_amount, 0.001);

            // The first cycle is recorded at what was collected — nothing — and has no
            // PayPlus charge to refund: the ₪1 was a card check, not a payment.
            $first = PaymentLedger::query()->where('plan_id', $plan->getKey())->sole();
            $this->assertSame(LedgerStatus::SUCCEEDED->value, $first->status instanceof LedgerStatus ? $first->status->value : (string) $first->status);
            $this->assertEqualsWithDelta(0.0, (float) $first->amount, 0.001);
            $this->assertNull($first->payplus_transaction_uid);

            // …and no row books the check's ₪1 as money received.
            $this->assertSame(0, PaymentLedger::query()->where('charge_context', PaymentLedger::CONTEXT_GATEWAY)->count());
            $this->assertEqualsWithDelta(0.0, (float) PaymentLedger::query()->sum('amount'), 0.001);
        });
    }

    public function test_a_free_first_cycle_without_a_saved_card_is_not_completed(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('free-notoken.example.com');
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0));
        $this->fakeGateway();

        $planId = (string) $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8720', 'amount' => 0, 'currency' => 'ILS', 'customer_id' => '55',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->json('subscription_plan_ids.0');

        Http::fake([
            ...$this->payplusIpn('gw:8720', ['amount' => '1.00']),
            '*/wp-json/wc/v3/orders/8720' => Http::response([
                'id' => 8720, 'status' => 'pending', 'total' => '0.00', 'currency' => 'ILS', 'customer_id' => 55,
                'billing' => ['email' => 'dana@example.com'],
                'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]],
            ], 200),
        ]);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:8720', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', false);

        // The order was never marked paid — no write that would complete it.
        Http::assertNotSent(fn ($r): bool => $r->method() === 'PUT' && str_ends_with($r->url(), '/orders/8720'));

        Tenant::run($shop, function () use ($planId): void {
            $plan = InstallmentPlan::query()->where('public_id', $planId)->sole();
            $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
            $this->assertSame(0, PaymentLedger::query()->count());
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

    /**
     * A subscription's first payment is offered the CARD and nothing else.
     *
     * PayPal, Bit, Multipass and the vouchers all take this cycle's money
     * perfectly well and leave nothing behind to charge the next one with. The
     * subscription would go live and fail at its first renewal while the shopper
     * believed they had subscribed — so the page hides them, whatever the
     * merchant enabled for ordinary checkouts.
     */
    public function test_a_subscription_page_offers_the_card_only(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-card-only.example.com');
        Tenant::run($shop, function () use ($shop): void {
            $this->makeSubscriptionProduct($shop, '501', 100.0);

            // The merchant has PayPal and Bit switched on for their normal page.
            MerchantCheckoutSettings::current()->forceFill([
                'allowed_charge_methods' => ['credit-card', 'bit', 'paypal'],
            ])->save();
        });
        $this->fakeGateway();

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8800', 'amount' => 100.0, 'currency' => 'ILS',
            'customer_id' => '55',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->assertOk();

        $payload = $this->gatewayPayloads[0];

        $this->assertSame([PayPlusPageOptions::TOKENISING_METHOD], $payload['allowed_charge_methods'] ?? null);
        $this->assertTrue((bool) ($payload['hide_other_charge_methods'] ?? false));
        $this->assertTrue((bool) ($payload['create_token'] ?? false));
    }

    /** …and an ordinary basket still gets every method the merchant enabled. */
    public function test_a_plain_order_keeps_the_merchants_own_payment_methods(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cart-methods.example.com');
        Tenant::run($shop, fn () => MerchantCheckoutSettings::current()->forceFill([
            'allowed_charge_methods' => ['credit-card', 'bit', 'paypal'],
        ])->save());
        $this->fakeGateway();

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '8900', 'amount' => 50.0, 'currency' => 'ILS',
        ])->assertOk();

        $this->assertSame(
            ['credit-card', 'bit', 'paypal'],
            $this->gatewayPayloads[0]['allowed_charge_methods'] ?? null,
        );
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
