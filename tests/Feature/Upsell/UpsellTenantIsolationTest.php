<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\UpsellMetrics;
use App\Domain\Upsell\UpsellResolver;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RELEASE BLOCKER: Shop A must NEVER see Shop B's upsell flows, offer events, or
 * metrics. The BelongsToShop global scope + explicit shop_id make every upsell
 * query fail closed. This is the test saas-multitenancy-billing re-runs each phase.
 */
final class UpsellTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_shop_cannot_read_another_shops_flows_or_metrics(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        // Shop B owns a matching active flow + a converted upsell event.
        Tenant::run($shopB, function () use ($shopB): void {
            $flow = $this->makeMatchingFlow($shopB);
            UpsellOfferEvent::record([
                'flow_id' => $flow->id,
                'offer_id' => $flow->offers()->first()->id,
                'event_type' => OfferEventType::CHARGE_SUCCEEDED,
                'revenue_amount' => 200.0,
            ]);
        });

        // Bound to Shop A: the resolver finds nothing (B's flow is invisible).
        Tenant::run($shopA, function () use ($shopA): void {
            $context = new PurchaseContext(
                shopId: (int) $shopA->getKey(),
                parentOrderId: 'X',
                customerRef: 'c',
                orderSubtotal: 999.0,
                purchasedProductGids: ['gid://shopify/Product/1'],
            );

            $this->assertNull(app(UpsellResolver::class)->resolve($context), 'Shop A must not resolve Shop B flows.');
            $this->assertSame(0, UpsellFlow::count(), 'Shop A sees no flows.');
            $this->assertSame(0, UpsellOfferEvent::count(), 'Shop A sees no events.');

            $metrics = app(UpsellMetrics::class)->overview();
            $this->assertSame(0, $metrics['charge_succeeded']);
            $this->assertSame(0.0, $metrics['total_revenue'], 'Shop A revenue must exclude Shop B.');
        });

        // Bound to Shop B: its own data is fully visible.
        Tenant::run($shopB, function (): void {
            $this->assertSame(1, UpsellFlow::count());
            $metrics = app(UpsellMetrics::class)->overview();
            $this->assertSame(200.0, $metrics['total_revenue']);
        });
    }

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function makeMatchingFlow(Shop $shop): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'B flow', 'priority' => 1]);
        $flow->shop_id = $shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
        ]);
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/77',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/770',
            'offer_title' => 'B add-on',
            'base_price' => 50.0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'position' => 0,
        ]);

        return $flow->fresh();
    }
}
