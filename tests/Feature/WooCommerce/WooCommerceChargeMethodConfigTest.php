<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\Orders\WooCommerceDepositInvoiceService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W17 — charge_method is config-driven, default 1 (immediate CAPTURE). 0 was verify-only (no
 * money captured — the bug). The deposit hosted-page generateLink payload carries
 * config('woocommerce.charge_method', 1); a flipped env changes the value with no code edit.
 */
final class WooCommerceChargeMethodConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> captured generateLink payloads */
    public array $payloads = [];

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_charge_method_defaults_to_one(): void
    {
        $payload = $this->runDeposit();

        // 1 = immediate capture (W17). Was 0 (verify-only) — the bug.
        $this->assertSame(1, $payload['charge_method']);
    }

    public function test_charge_method_reads_the_configured_value(): void
    {
        // Owner verified the terminal needs a different immediate-charge code.
        config()->set('woocommerce.charge_method', 2);

        $payload = $this->runDeposit();

        $this->assertSame(2, $payload['charge_method']);
    }

    // === Helpers ===

    /** Run the deposit invoice service and return the generateLink payload it sent. */
    private function runDeposit(): array
    {
        $shop = $this->makeShop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop);
            (new WooCommerceDepositInvoiceService())->createDepositInvoice($plan, [
                'title' => 'Reserved Item', 'deposit_amount' => 125.50, 'quantity' => 1, 'variant_gid' => '1',
            ]);
        });

        return $this->payloads[0];
    }

    private function fakeGateway(): void
    {
        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private WooCommerceChargeMethodConfigTest $test) {}

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
                $this->test->payloads[] = $payload;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['page_request_uid' => 'PR-1', 'payment_page_link' => 'https://pay.example/p/PR-1'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    private function makeShop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'cm.example.com',
            'name' => 'ChargeMethod WC',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->woocommerce_credentials = ['base_url' => 'https://cm.example.com'];
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        return $shop->fresh();
    }

    private function makePlan(Shop $shop): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'charge_context' => 'deposit',
            'total_amount' => 500,
            'total_charged' => 0,
            'installment_amount' => 125,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'public_id' => 'PUB-CM-1',
            'meta' => [],
        ]);
        $plan->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        ])->save();

        return $plan->fresh();
    }
}
