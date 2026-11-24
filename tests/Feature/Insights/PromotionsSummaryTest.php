<?php

namespace Tests\Feature\Insights;

use App\Domain\Insights\InsightsClock;
use App\Domain\Insights\PromotionsSummary;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Filament\Pages\Insights\Promotions;
use App\Models\ActivityEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The promotions screen reads only what the app already records — so these pin the
 * rules it adds on top: one order's discount split over its codes, a refresh or a
 * double tap counted once, a refund taken off, a switch counted once, and nothing
 * from another shop.
 */
final class PromotionsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('promo.example.com');
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_orders_discount_is_split_over_its_codes(): void
    {
        $this->event(Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED, ['coupon_codes' => ['welcome10', 'VIP'], 'amount' => 30]);
        $this->event(Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED, ['coupon_codes' => ['WELCOME10'], 'amount' => 10]);

        $rows = collect($this->summary()->coupons(...$this->window()))->keyBy('code');

        $this->assertSame(2, $rows['WELCOME10']['uses']);
        $this->assertEqualsWithDelta(25.0, $rows['WELCOME10']['discount'], 0.001);
        $this->assertSame(1, $rows['VIP']['uses']);
        $this->assertEqualsWithDelta(15.0, $rows['VIP']['discount'], 0.001);
    }

    public function test_after_sell_counts_each_order_once_and_takes_refunds_off(): void
    {
        $ledger = $this->ledger(PaymentLedger::CONTEXT_UPSELL, 50, refunded: 20);
        $flow = new UpsellFlow(['name' => 'Books', 'priority' => 1]);
        $flow->shop_id = $this->shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();
        $offer = UpsellFlowOffer::create(['flow_id' => $flow->id, 'offer_product_gid' => '', 'offer_variant_gid' => '', 'offer_title' => 'Travel pouch', 'base_price' => 0, 'discount_type' => UpsellFlowOffer::DISCOUNT_NONE, 'position' => 0]);
        $ids = ['flow_id' => $flow->id, 'offer_id' => $offer->getKey()];

        foreach (['impression', 'impression', 'accepted', 'accepted'] as $type) {
            UpsellOfferEvent::record($ids + ['event_type' => $type, 'parent_order_id' => '1001']);
        }
        UpsellOfferEvent::record($ids + ['event_type' => 'charge_succeeded', 'parent_order_id' => '1001', 'revenue_amount' => 50, 'payment_ledger_id' => $ledger->getKey()]);
        UpsellOfferEvent::record($ids + ['event_type' => 'impression', 'parent_order_id' => '1002']);

        $row = $this->summary()->afterSell(...$this->window())[0];

        $this->assertSame('Travel pouch', $row['title']);
        $this->assertSame(2, $row['seen']);
        $this->assertSame(1, $row['taken']);
        $this->assertSame(1, $row['sold']);
        $this->assertEqualsWithDelta(30.0, $row['revenue'], 0.001);
    }

    public function test_a_switch_written_on_both_plans_counts_once(): void
    {
        $ledger = $this->ledger(PaymentLedger::CONTEXT_ACCOUNT_OFFER, 40);
        $switch = ['offer_id' => 7, 'offer_name' => 'Upgrade', 'new_plan' => 'pl_new', 'amount' => 40, 'ledger_id' => $ledger->getKey()];
        $this->event(Timeline::KIND_ACCOUNT_OFFER_ACCEPTED, $switch);
        $this->event(Timeline::KIND_ACCOUNT_OFFER_ACCEPTED, $switch);

        $row = $this->summary()->accountOffers(...$this->window())[0];

        $this->assertSame('Upgrade', $row['title']);
        $this->assertSame(1, $row['taken']);
        $this->assertEqualsWithDelta(40.0, $row['charged'], 0.001);
    }

    public function test_another_shops_promotions_never_show(): void
    {
        $other = $this->makeShop('other.example.com');
        Tenant::set($other);
        $this->event(Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED, ['coupon_codes' => ['THEIRS'], 'amount' => 5]);
        Tenant::set($this->shop);

        $this->assertSame([], $this->summary()->coupons(...$this->window()));
    }

    public function test_the_screen_renders_every_part(): void
    {
        $this->event(Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED, ['coupon_codes' => ['SAVE5'], 'amount' => 5]);

        $page = Livewire::test(Promotions::class)->assertOk()->assertSee('SAVE5');

        foreach (Promotions::PARTS as $part) {
            $page->call('setPart', $part)->assertOk()->assertSet('part', $part);
        }
        $page->call('setPart', 'nonsense')->assertSet('part', Promotions::PARTS[0]);
    }

    private function summary(): PromotionsSummary
    {
        return new PromotionsSummary;
    }

    /** @return list<CarbonImmutable> */
    private function window(): array
    {
        $w = PromotionsSummary::instants(InsightsClock::today()->subDays(6), InsightsClock::today());

        return [$w['from'], $w['to']];
    }

    /** @param array<string, mixed> $details */
    private function event(string $kind, array $details): void
    {
        ActivityEvent::query()->create(['kind' => $kind, 'details' => $details, 'created_at' => now()]);
    }

    private function ledger(string $context, float $amount, float $refunded = 0): PaymentLedger
    {
        $row = new PaymentLedger;
        $row->forceFill([
            'shop_id' => Tenant::id(),
            'charge_context' => $context,
            'idempotency_key' => (string) Str::uuid(),
            'amount' => $amount,
            'refunded_amount' => $refunded,
            'status' => $refunded > 0 ? PaymentLedger::STATUS_REFUNDED : PaymentLedger::STATUS_SUCCEEDED,
        ])->save();

        return $row;
    }

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }
}
