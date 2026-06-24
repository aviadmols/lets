<?php

namespace Tests\Feature\Platform;

use App\Domain\Installments\DepositPlanService;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Orders\PaidOrderPlanResolver;
use App\Services\Orders\PaidOrderPlanResolverFactory;
use App\Services\Shopify\Orders\ShopifyPaidOrderPlanResolver;
use App\Services\WooCommerce\Orders\WooCommercePaidOrderPlanResolver;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W11 Phase 0 — the paid-order → plan lookup seam. PlanActivationService now finds
 * the plan via the platform's PaidOrderPlanResolver. The Shopify resolver holds the
 * exact lookup PlanActivationService used before (note_attributes + draft_order_id),
 * tenant-scoped. New coverage — this lookup previously had no tests.
 */
final class PaidOrderPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PaidOrderPlanResolverFactory::clearFake();
        parent::tearDown();
    }

    public function test_factory_routes_each_platform_to_its_resolver(): void
    {
        $this->assertInstanceOf(
            ShopifyPaidOrderPlanResolver::class,
            PaidOrderPlanResolverFactory::for(new Shop(['platform' => Shop::PLATFORM_SHOPIFY])),
        );
        $this->assertInstanceOf(
            PaidOrderPlanResolver::class,
            PaidOrderPlanResolverFactory::for(new Shop(['platform' => Shop::PLATFORM_SHOPIFY])),
        );
        // W11 P2: WooCommerce now resolves to its own meta/callback resolver (was null).
        $this->assertInstanceOf(
            WooCommercePaidOrderPlanResolver::class,
            PaidOrderPlanResolverFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE])),
        );
    }

    public function test_resolves_by_plan_public_id_note_attribute(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        $plan = $this->makePlan($shop, ['public_id' => 'PUB-1']);

        $resolved = Tenant::run($shop, fn (): ?InstallmentPlan => (new ShopifyPaidOrderPlanResolver())->resolve($shop, [
            'note_attributes' => [['name' => 'pps_plan_public_id', 'value' => 'PUB-1']],
        ]));

        $this->assertSame($plan->getKey(), $resolved?->getKey());
    }

    public function test_resolves_by_draft_order_id_meta_fallback(): void
    {
        $shop = $this->makeShop('b.myshopify.com');
        $plan = $this->makePlan($shop, [
            'public_id' => 'PUB-2',
            'meta' => [DepositPlanService::META_DRAFT_ID => 'D-77'],
        ]);

        $resolved = Tenant::run($shop, fn (): ?InstallmentPlan => (new ShopifyPaidOrderPlanResolver())->resolve($shop, [
            'draft_order_id' => 'D-77',
        ]));

        $this->assertSame($plan->getKey(), $resolved?->getKey());
    }

    public function test_returns_null_when_no_signal_matches(): void
    {
        $shop = $this->makeShop('c.myshopify.com');
        $this->makePlan($shop, ['public_id' => 'PUB-3']);

        $resolved = Tenant::run($shop, fn (): ?InstallmentPlan => (new ShopifyPaidOrderPlanResolver())->resolve($shop, [
            'note_attributes' => [],
            'draft_order_id' => 'nope',
        ]));

        $this->assertNull($resolved);
    }

    public function test_is_tenant_scoped_and_never_resolves_another_shops_plan(): void
    {
        $shopA = $this->makeShop('isoa.myshopify.com');
        $this->makePlan($shopA, ['public_id' => 'PUB-A']);
        $shopB = $this->makeShop('isob.myshopify.com');

        // Resolving inside shop B's tenant must NOT find shop A's plan (BelongsToShop).
        $resolved = Tenant::run($shopB, fn (): ?InstallmentPlan => (new ShopifyPaidOrderPlanResolver())->resolve($shopB, [
            'note_attributes' => [['name' => 'pps_plan_public_id', 'value' => 'PUB-A']],
        ]));

        $this->assertNull($resolved);
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function makePlan(Shop $shop, array $attrs): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $attrs): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill(array_merge([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'charge_context' => 'deposit',
                'total_amount' => 100,
                'total_charged' => 0,
                'installment_amount' => 25,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'public_id' => (string) Str::ulid(),
            ], $attrs));
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            return $plan->fresh();
        });
    }
}
