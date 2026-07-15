<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Models\MerchantUpsellAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The serializer that feeds the ONE shared renderer (Phase 3). Money is SERVER-computed from
 * discountedPrice() and formatted server-side; the disclosure carries the exact charge amount; the
 * appearance block's elements are locked-enforced; the timer is null unless the offer opts in.
 */
final class UpsellCardPresenterTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['name' => 'P', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        config(['payplus.currency' => 'ILS']);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_money_is_server_computed_and_matches_discounted_price(): void
    {
        $offer = $this->offer(base: 100.0, discountType: UpsellFlowOffer::DISCOUNT_PERCENT, discountValue: 20);

        $vm = $this->presenter()->forOffer($offer, MerchantUpsellAppearance::current(), UpsellCardPresenter::PLATFORM_WOOCOMMERCE);
        $c = $vm['content'];

        $this->assertSame('woocommerce', $vm['platform']);
        $this->assertEqualsWithDelta($offer->discountedPrice(), $c['price'], 0.001);
        $this->assertEqualsWithDelta(80.0, $c['price'], 0.001);
        $this->assertSame('₪80.00', $c['price_display']);
        $this->assertSame('₪100.00', $c['was_display']);
        $this->assertTrue($c['has_discount']);
        $this->assertSame(20, $c['save_percent']);
        $this->assertStringContainsString('20.00', (string) $c['save_label']);
        // The consent disclosure carries the EXACT amount that will be charged.
        $this->assertStringContainsString('₪80.00', (string) $c['disclosure']);
    }

    public function test_no_discount_offer_has_no_was_or_save(): void
    {
        $offer = $this->offer(base: 60.0);

        $c = $this->presenter()->forOffer($offer, MerchantUpsellAppearance::current(), UpsellCardPresenter::PLATFORM_WOOCOMMERCE)['content'];

        $this->assertFalse($c['has_discount']);
        $this->assertNull($c['was_display']);
        $this->assertNull($c['save_label']);
        $this->assertSame('₪60.00', $c['price_display']);
    }

    public function test_timer_seconds_only_when_the_offer_opts_in(): void
    {
        $off = $this->offer(base: 40.0, showTimer: false, timerMinutes: 5);
        $on = $this->offer(base: 40.0, showTimer: true, timerMinutes: 5);

        $p = $this->presenter();
        $app = MerchantUpsellAppearance::current();

        $this->assertNull($p->forOffer($off, $app, 'woocommerce')['content']['timer_seconds']);
        $this->assertSame(300, $p->forOffer($on, $app, 'woocommerce')['content']['timer_seconds']);
    }

    public function test_appearance_block_is_locked_enforced_and_ordered(): void
    {
        $offer = $this->offer(base: 10.0);
        $app = MerchantUpsellAppearance::current();
        $app->forceFill(['elements' => [['key' => 'price', 'enabled' => false]]]); // try to kill the price

        $appearance = $this->presenter()->forOffer($offer, $app, 'woocommerce')['appearance'];
        $keys = array_column($appearance['elements'], 'key');

        foreach (MerchantUpsellAppearance::LOCKED_ELEMENTS as $locked) {
            $this->assertContains($locked, $keys);
            $this->assertTrue(collect($appearance['elements'])->firstWhere('key', $locked)['enabled']);
        }
    }

    public function test_sample_uses_a_fixed_price_and_reflects_the_merchant_copy(): void
    {
        $app = MerchantUpsellAppearance::current();
        $app->forceFill(['eyebrow_text' => 'Members only', 'badge_text' => 'Hot']);

        $vm = $this->presenter()->sample($app, 'woocommerce');

        $this->assertTrue($vm['is_sample']);
        $this->assertSame('₪79.90', $vm['content']['price_display']);
        $this->assertSame('Members only', $vm['content']['eyebrow']);
        $this->assertSame('Hot', $vm['content']['badge']);
    }

    private function presenter(): UpsellCardPresenter
    {
        return app(UpsellCardPresenter::class);
    }

    private function offer(float $base, string $discountType = UpsellFlowOffer::DISCOUNT_NONE, float $discountValue = 0, bool $showTimer = false, ?int $timerMinutes = null): UpsellFlowOffer
    {
        $flow = new UpsellFlow(['name' => 'F', 'priority' => 1]);
        $flow->shop_id = $this->shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

        return UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/1',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/10',
            'offer_title' => 'Sample',
            'base_price' => $base,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'show_timer' => $showTimer,
            'timer_minutes' => $timerMinutes,
            'position' => 0,
        ]);
    }
}
