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
use App\Services\Orders\PlatformInvoiceServiceFactory;
use App\Services\WooCommerce\Orders\WooCommerceDepositInvoiceService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W11 P2 — the WooCommerce deposit invoice service maps PayPlus generateLink onto the
 * platform-neutral linkage DepositPlanService stores. It asks the per-shop gateway for a
 * HOSTED page (no charge here — the page collects the deposit) and returns:
 *   external_ref = plan public_id (echoed back as more_info on the callback),
 *   external_gid = data.page_request_uid, invoice_url = data.payment_page_link.
 */
final class WooCommerceDepositInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> captured generateLink payloads */
    public array $generateLinkPayloads = [];

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        PlatformInvoiceServiceFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_factory_routes_a_woocommerce_shop_to_this_service(): void
    {
        $service = PlatformInvoiceServiceFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE]));

        $this->assertInstanceOf(WooCommerceDepositInvoiceService::class, $service);
    }

    public function test_create_deposit_invoice_maps_generate_link_to_the_neutral_keys(): void
    {
        $shop = $this->makeShop();
        $this->fakeGateway(success: true);

        [$plan, $invoice] = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->makePlan($shop, 'PUB-INV-1');
            $invoice = (new WooCommerceDepositInvoiceService())->createDepositInvoice($plan, [
                'title' => 'Reserved Sofa',
                'deposit_amount' => 125.50,
                'quantity' => 1,
                'variant_gid' => '987',
            ]);

            return [$plan, $invoice];
        });

        // Neutral shape mapping (the contract DepositPlanService consumes).
        $this->assertSame('PUB-INV-1', $invoice['external_ref']);          // = plan public_id (echoed as more_info)
        $this->assertSame('PR-UID-77', $invoice['external_gid']);          // = data.page_request_uid
        $this->assertSame('https://pay.example/page/PR-UID-77', $invoice['invoice_url']); // = data.payment_page_link
        $this->assertSame('Reserved Sofa', $invoice['name']);

        // generateLink got the deposit money + more_info = the plan public_id (the callback echoes it).
        $payload = $this->generateLinkPayloads[0];
        $this->assertSame(125.50, $payload['amount']);
        $this->assertSame('PUB-INV-1', $payload['more_info']);
        $this->assertArrayHasKey('refURL_callback', $payload);
        $this->assertArrayHasKey('refURL_success', $payload);
    }

    public function test_a_failed_generate_link_throws(): void
    {
        $shop = $this->makeShop();
        $this->fakeGateway(success: false);

        $this->expectException(\RuntimeException::class);

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'PUB-INV-2');
            (new WooCommerceDepositInvoiceService())->createDepositInvoice($plan, [
                'title' => 'X', 'deposit_amount' => 50.0, 'quantity' => 1, 'variant_gid' => '1',
            ]);
        });
    }

    // === Helpers ===

    private function fakeGateway(bool $success): void
    {
        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test, $success) implements PayPlusGatewayInterface
        {
            public function __construct(private WooCommerceDepositInvoiceServiceTest $test, private bool $success) {}

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
                $this->test->generateLinkPayloads[] = $payload;

                if (! $this->success) {
                    return GatewayResult::fromResponse(['results' => ['status' => 'error', 'code' => 9, 'description' => 'nope']]);
                }

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => [
                        'page_request_uid' => 'PR-UID-77',
                        'payment_page_link' => 'https://pay.example/page/PR-UID-77',
                    ],
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
            'woocommerce_domain' => 'invoice.example.com',
            'name' => 'Invoice WC',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->woocommerce_credentials = ['base_url' => 'https://invoice.example.com'];
        // PayPlus creds present so the (faked) gateway factory resolves cleanly.
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        return $shop->fresh();
    }

    private function makePlan(Shop $shop, string $publicId): InstallmentPlan
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
            'public_id' => $publicId,
            'meta' => [],
        ]);
        $plan->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        ])->save();

        return $plan->fresh();
    }
}
