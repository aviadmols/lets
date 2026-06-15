<?php

namespace Tests\Feature\Shopify;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Shopify\Orders\DefaultShopifyOrderStrategy;
use App\Services\Shopify\ShopifyAdminApi;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Shopify order strategy (§5). Uses a recording fake Admin client (no HTTP) to
 * assert the proven Shopify SHAPE survives the multi-tenant refactor:
 *   - deposit ⇒ a LOCKED parent order with NO transactions (the duplicate-invoice
 *     scar), and the lock metafield set true.
 *   - final installment ⇒ fulfillment released (markAsPaid + createFulfillment).
 *   - the per-shop client is resolved for the plan's OWN shop (no leak).
 */
final class ShopifyOrderStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_deposit_creates_locked_parent_order_with_no_transactions(): void
    {
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        $fake = $this->fakeClientFor($shop);

        $plan = $this->makePlan($shop, PlanKind::INSTALLMENTS, totalAmount: 300);

        Tenant::run($shop, fn () => (new DefaultShopifyOrderStrategy())->materialize($plan, ChargeContext::DEPOSIT));

        // Exactly one order created; it carries NO transactions block.
        $this->assertCount(1, $fake->createdOrders);
        $parent = $fake->createdOrders[0];
        $this->assertArrayNotHasKey('transactions', $parent, 'Parent order must carry NO transactions (duplicate-invoice scar).');
        $this->assertSame('pending', $parent['financial_status']);

        // The fulfillment_lock metafield was set true.
        $lockKey = (string) config('shopify.metafields.fulfillment_lock');
        $this->assertTrue(collect($fake->metafields)->contains(
            fn (array $m): bool => $m['key'] === $lockKey && $m['value'] === 'true'
        ));

        // The plan now references the created Shopify order.
        $this->assertSame('555000111', $plan->fresh()->shopify_order_id);
    }

    public function test_final_installment_releases_fulfillment(): void
    {
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        $fake = $this->fakeClientFor($shop);

        // A fully-paid installments plan already linked to a Shopify order.
        $plan = $this->makePlan($shop, PlanKind::INSTALLMENTS, totalAmount: 300, totalCharged: 300);
        $plan->forceFill([
            'shopify_order_id' => '900',
            'shopify_order_gid' => 'gid://shopify/Order/900',
        ])->save();

        Tenant::run($shop, fn () => (new DefaultShopifyOrderStrategy())->materialize($plan, ChargeContext::INSTALLMENT, isFinal: true));

        $this->assertTrue($fake->markedPaid, 'orderMarkAsPaid must run at completion.');
        $this->assertTrue($fake->fulfillmentCreated, 'createFulfillment must run at completion.');
    }

    public function test_recurring_creates_a_new_paid_order_with_inline_transaction(): void
    {
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        $fake = $this->fakeClientFor($shop);

        $plan = $this->makePlan($shop, PlanKind::RECURRING, installmentAmount: 49.90);

        Tenant::run($shop, fn () => (new DefaultShopifyOrderStrategy())->materialize($plan, ChargeContext::RECURRING));

        $this->assertCount(1, $fake->createdOrders);
        $order = $fake->createdOrders[0];
        $this->assertArrayHasKey('transactions', $order, 'Recurring/child orders DO carry the inline sale transaction.');
        $this->assertSame('manual', $order['transactions'][0]['gateway']);
        $this->assertSame('external', $order['transactions'][0]['source']);
    }

    // === Helpers ===

    private function makeInstalledShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->captureShopifyInstall('shpat_token_'.$domain, 'read_orders,write_orders');

        return $shop->fresh();
    }

    private function makePlan(Shop $shop, PlanKind $kind, float $totalAmount = 0, float $totalCharged = 0, float $installmentAmount = 0): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($kind, $totalAmount, $totalCharged, $installmentAmount): InstallmentPlan {
            $plan = InstallmentPlan::create([
                'plan_kind' => $kind->value,
                'total_amount' => $totalAmount,
                'total_charged' => $totalCharged,
                'installment_amount' => $installmentAmount ?: null,
                'currency' => 'ILS',
                'public_id' => 'PLAN-'.uniqid(),
                'customer_email' => 'buyer@example.com',
                'shopify_variant_id' => '111222333',
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        });
    }

    private function fakeClientFor(Shop $shop): RecordingShopifyClient
    {
        $fake = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (Shop $s): ShopifyAdminApi => $fake);

        return $fake;
    }
}
