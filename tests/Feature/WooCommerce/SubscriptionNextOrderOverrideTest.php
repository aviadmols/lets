<?php

namespace Tests\Feature\WooCommerce;

use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W25 — the ONE-TIME "next order" override drives a single recurring cycle: the charged amount is
 * the override total (not installment_amount), the WC cycle order carries the override's real
 * product line, and the override is CLEARED after a successful charge (the following cycle reverts
 * to normal). Money law: the amount is read server-side from the plan, never client-supplied at
 * charge time. Reuses the WooCommerceRecurringChargeMaterializeTest harness (PayPlus faked, WC REST
 * Http::faked).
 */
final class SubscriptionNextOrderOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<float> */
    public array $charged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->charged = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private SubscriptionNextOrderOverrideTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->charged[] = $amount;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.count($this->test->charged), 'approval_number' => 'A1']],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_override_prices_the_cycle_shapes_the_order_and_is_cleared_after_success(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 9200], 201)]);

        [$shop, $plan] = $this->recurringPlan();

        // A one-time next-order override: 2 × ₪30 = ₪60 (the plan's normal amount is ₪49.90).
        Tenant::run($shop, fn () => $plan->forceFill(['meta' => ['next_order' => [
            'line_items' => [['product_id' => 2670, 'name' => 'Coffee bag', 'quantity' => 2, 'unit_price' => 30.00]],
            'amount' => 60.00,
            'currency' => 'ILS',
        ]]])->save());

        Tenant::set($shop);
        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);
        $this->assertTrue($outcome->isSucceeded());

        // 1) PayPlus was charged the OVERRIDE total, not installment_amount.
        $this->assertCount(1, $this->charged);
        $this->assertEqualsWithDelta(60.00, $this->charged[0], 0.001);

        // 2) The WC cycle order carries the override's real product line + total.
        Http::assertSent(function (Request $req): bool {
            $b = $req->data();
            if (! str_contains($req->url(), '/wp-json/wc/v3/orders') || $req->method() !== 'POST') {
                return false;
            }
            $line = $b['line_items'][0] ?? [];

            return (int) ($line['product_id'] ?? 0) === 2670
                && (int) ($line['quantity'] ?? 0) === 2
                && (string) ($line['total'] ?? '') === '60.00'
                && $this->meta($b, 'lets_paid_amount') === '60.00';
        });

        // 3) The override is CONSUMED — cleared so the next cycle reverts to normal.
        $plan->refresh();
        $this->assertNull(data_get($plan->meta, 'next_order'));
        $this->assertContains('9200', (array) data_get($plan->meta, 'wc_recurring_order_ids'));
    }

    public function test_without_an_override_the_cycle_charges_the_plan_amount(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 9300], 201)]);

        [$shop, $plan] = $this->recurringPlan();
        Tenant::set($shop);

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertEqualsWithDelta(49.90, $this->charged[0], 0.001);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
            && str_contains($req->url(), '/wp-json/wc/v3/orders')
            && ! isset($req->data()['line_items'][0]['product_id'])); // the plain synthetic line
    }

    /** @return array{0:Shop,1:InstallmentPlan} */
    private function recurringPlan(): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'ovr-'.Str::random(6).'.example.com',
            'name' => 'Override E2E',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->woocommerce_credentials = ['base_url' => 'https://ovr.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'];
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1', 'payplus_customer_uid' => 'cust-1',
                'card_last_four' => '4242', 'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);
            CustomerConsent::create([
                'shopify_customer_id' => 'wc-cust-1',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING, 'accepted_at' => now(),
            ]);
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value, 'charge_context' => 'recurring',
                'payment_method_id' => $method->id, 'shopify_customer_id' => 'wc-cust-1',
                'installment_amount' => 49.90, 'billing_frequency' => 'monthly', 'interval_count' => 1,
                'currency' => 'ILS', 'customer_email' => 'shopper@example.com', 'next_charge_at' => now(),
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan->fresh();
        });

        return [$shop, $plan];
    }

    private function meta(array $body, string $key): mixed
    {
        foreach ((array) ($body['meta_data'] ?? []) as $m) {
            if (($m['key'] ?? null) === $key) {
                return $m['value'] ?? null;
            }
        }

        return null;
    }
}
