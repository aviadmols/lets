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
 * W11 P3 — END-TO-END: a RECURRING charge driven through the real ChargeOrchestrator for
 * a WooCommerce shop must, AFTER the succeeded ledger row, materialize a per-cycle PAID WC
 * order via the factory-resolved WooCommerceOrderStrategy. PayPlus is faked (charge
 * succeeds); WC REST is Http::faked. This proves the orchestrator → PlatformOrderStrategy
 * Factory → WooCommerceOrderStrategy wiring (the gap the missing strategy left).
 */
final class WooCommerceRecurringChargeMaterializeTest extends TestCase
{
    use RefreshDatabase;

    public int $payplusCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payplusCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private WooCommerceRecurringChargeMaterializeTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->payplusCalls++;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$this->test->payplusCalls, 'approval_number' => 'A1']],
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

    public function test_a_recurring_charge_for_a_wc_shop_materializes_a_paid_cycle_order(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 9100], 201)]);

        [$shop, $plan] = $this->recurringPlan();
        Tenant::set($shop);

        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertTrue($outcome->isSucceeded());
        $this->assertSame(1, $this->payplusCalls);

        // The succeeded recurring charge created exactly ONE paid WC cycle order, linked.
        Http::assertSent(function (Request $req) use ($plan) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders')
                && $req->method() === 'POST'
                && ($b['status'] ?? null) === 'completed'
                && ($b['set_paid'] ?? null) === true
                && $this->meta($b, 'lets_plan_public_id') === (string) $plan->public_id
                && $this->meta($b, 'lets_order_role') === 'recurring_order';
        });

        $plan->refresh();
        $this->assertContains('9100', (array) data_get($plan->meta, 'wc_recurring_order_ids'));
    }

    /** @return array{0:Shop,1:InstallmentPlan} */
    private function recurringPlan(): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'rec-e2e.example.com',
            'name' => 'Rec E2E',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->woocommerce_credentials = ['base_url' => 'https://rec-e2e.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'];
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'wc-cust-1',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'wc-cust-1',
                'installment_amount' => 49.90,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'customer_email' => 'shopper@example.com',
                'next_charge_at' => now(),
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan->fresh();
        });

        return [$shop, $plan];
    }

    /** Read a WC meta_data value by key from a request body. */
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
