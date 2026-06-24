<?php

namespace Tests\Feature\Platform;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\InstallmentQuote;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Orders\PlatformInvoiceService;
use App\Services\Orders\PlatformInvoiceServiceFactory;
use App\Services\Shopify\Orders\ShopifyDepositInvoiceAdapter;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W11 Phase 0 — the deposit-invoice seam. DepositPlanService now resolves the
 * platform's invoice service via the factory and stores a PLATFORM-NEUTRAL linkage
 * (external_ref / external_gid / invoice_url) on the plan. Shopify resolves the draft
 * adapter (byte-identical); WooCommerce has none yet (P2). The create() coverage here
 * is new — the service previously had no tests.
 */
final class PlatformInvoiceServiceFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PlatformInvoiceServiceFactory::clearFake();
        parent::tearDown();
    }

    public function test_shopify_shop_resolves_the_shopify_draft_adapter(): void
    {
        // The Shopify adapter is built over a PER-SHOP client (ShopifyClientFactory::for),
        // which requires an installed shop with a token — exactly what the real caller
        // (DepositPlanService::create) always passes.
        $shop = Shop::create([
            'shopify_domain' => 'inv.myshopify.com',
            'name' => 'Inv',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->captureShopifyInstall('shpat_token', 'read_orders');

        $service = PlatformInvoiceServiceFactory::for($shop->fresh());

        $this->assertInstanceOf(ShopifyDepositInvoiceAdapter::class, $service);
        $this->assertInstanceOf(PlatformInvoiceService::class, $service);
    }

    public function test_woocommerce_shop_has_no_invoice_service_yet(): void
    {
        $this->assertNull(PlatformInvoiceServiceFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE])));
    }

    public function test_create_builds_a_tenant_scoped_plan_and_stores_the_neutral_invoice_linkage(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'dep.myshopify.com',
            'name' => 'Dep',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        // The invoice service is faked to return the platform-neutral shape; this
        // proves DepositPlanService maps external_ref/gid/url onto the plan meta.
        PlatformInvoiceServiceFactory::fake(new class implements PlatformInvoiceService
        {
            public function createDepositInvoice(InstallmentPlan $plan, array $lineItem): array
            {
                return [
                    'external_ref' => 'EXT-555',
                    'external_gid' => 'gid://x/555',
                    'invoice_url' => 'https://pay.example/inv/555',
                    'name' => 'INV-555',
                ];
            }
        });

        $quote = InstallmentQuote::build(
            totalAmount: 400.0, depositPercent: 25, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
        );

        $result = Tenant::run($shop, fn (): array => (new DepositPlanService())->create($shop, $quote, [
            'product_gid' => 'gid://shopify/Product/1',
            'variant_gid' => 'gid://shopify/ProductVariant/2',
            'item_title' => 'Sofa',
        ]));

        $plan = $result['plan'];
        $this->assertSame('https://pay.example/inv/555', $result['invoice_url']);
        $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
        $this->assertSame((int) $shop->getKey(), (int) $plan->shop_id);
        $this->assertSame('EXT-555', data_get($plan->meta, DepositPlanService::META_DRAFT_ID));
        $this->assertSame('gid://x/555', data_get($plan->meta, DepositPlanService::META_DRAFT_GID));
        $this->assertSame('https://pay.example/inv/555', data_get($plan->meta, DepositPlanService::META_INVOICE_URL));
        $this->assertSame('EXT-555', $plan->shopify_order_id);
    }
}
