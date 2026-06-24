<?php

namespace Tests\Feature\Platform;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Services\Orders\PlatformOrderStrategy;
use App\Services\Orders\PlatformOrderStrategyFactory;
use App\Services\Shopify\Orders\ShopifyOrderStrategy;
use Tests\TestCase;

/**
 * W11 Phase 0 — the order-strategy seam. The ChargeOrchestrator materializes store
 * state through a platform-keyed strategy. Shopify resolves the bound Shopify
 * strategy (so Shopify behaviour is byte-identical); WooCommerce has no strategy yet
 * (ships Phase 2) and resolves to null so the engine runs decoupled for it. Only the
 * `platform` attribute is read, so unsaved Shop instances suffice (no DB).
 */
final class PlatformOrderStrategyFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        PlatformOrderStrategyFactory::clearFake();
        parent::tearDown();
    }

    public function test_shopify_shop_resolves_the_bound_shopify_strategy(): void
    {
        $strategy = PlatformOrderStrategyFactory::for(new Shop(['platform' => Shop::PLATFORM_SHOPIFY]));

        $this->assertInstanceOf(ShopifyOrderStrategy::class, $strategy);
        $this->assertInstanceOf(PlatformOrderStrategy::class, $strategy);
    }

    public function test_unknown_platform_defaults_to_the_shopify_strategy(): void
    {
        // The Shop::platform accessor defaults unknown/blank values to shopify, so a
        // bad/missing platform never leaves a charge without a strategy.
        $strategy = PlatformOrderStrategyFactory::for(new Shop(['platform' => 'nonsense']));

        $this->assertInstanceOf(ShopifyOrderStrategy::class, $strategy);
    }

    public function test_woocommerce_shop_has_no_strategy_yet(): void
    {
        // W11 Phase 0: the WooCommerce order strategy ships in Phase 2; until then the
        // factory returns null and the engine runs decoupled for WooCommerce shops.
        $this->assertNull(PlatformOrderStrategyFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE])));
    }

    public function test_fake_overrides_every_resolution(): void
    {
        $fake = new class implements PlatformOrderStrategy
        {
            public bool $called = false;

            public function materialize(InstallmentPlan $plan, ChargeContext $context, bool $isFinal = false): void
            {
                $this->called = true;
            }
        };
        PlatformOrderStrategyFactory::fake($fake);

        $this->assertSame($fake, PlatformOrderStrategyFactory::for(new Shop(['platform' => Shop::PLATFORM_SHOPIFY])));
        $this->assertSame($fake, PlatformOrderStrategyFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE])));
    }
}
