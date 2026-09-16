<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Filament\Pages\FlowBuilder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Building a bundle in the offer drawer: which products, how many the shopper picks, the
 * one price, how many per slide, and how long the offer stays open.
 *
 * What the drawer must never do is store a product id from somewhere else, or let a
 * half-built bundle look ready.
 */
final class FlowBuilderBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_woocommerce_merchant_builds_a_bundle_in_the_drawer(): void
    {
        $shop = $this->signIn(Shop::PLATFORM_WOOCOMMERCE);
        $a = $this->book('Atlas', '301');
        $b = $this->book('Borders', '302');
        $c = $this->book('Cosmos', '303');
        $foreign = Tenant::run($this->shop(Shop::PLATFORM_WOOCOMMERCE, 'rival.example.com'), fn (): Product => $this->book('Foreign', '999'));
        Tenant::set($shop);
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->call('addBundleProduct', $c->id)
            ->call('addBundleProduct', $a->id)
            ->call('addBundleProduct', $a->id)          // twice: listed once
            ->call('addBundleProduct', $foreign->id)    // another shop's: never listed
            ->call('addBundleProduct', $b->id)
            ->set('bundleQuantity', '2')
            ->set('bundlePrice', '80')
            ->set('bundleColumns', 3)
            ->set('timerMinutes', '5')
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::query()->findOrFail($offerId);

        $this->assertSame([$c->id, $a->id, $b->id], $offer->bundleProductIds(), 'in the order they were added');
        $this->assertSame(2, $offer->bundle_quantity);
        $this->assertEqualsWithDelta(80.0, (float) $offer->bundle_price, 0.001);
        $this->assertSame(3, $offer->bundle_columns);
        $this->assertSame(5, $offer->timer_minutes);
        $this->assertTrue($offer->isBundle());
        $this->assertEqualsWithDelta(80.0, $offer->discountedPrice(), 0.001, 'the bundle price is what the offer charges');
    }

    public function test_a_product_can_be_taken_out_of_the_bundle(): void
    {
        $shop = $this->signIn(Shop::PLATFORM_WOOCOMMERCE);
        $a = $this->book('Atlas', '301');
        $b = $this->book('Borders', '302');
        $flow = $this->flow($shop);

        $rows = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $flow->offers()->first()->id)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->call('addBundleProduct', $a->id)
            ->call('addBundleProduct', $b->id)
            ->call('removeBundleProduct', $a->id)
            ->instance()
            ->bundleProductRows();

        $this->assertSame([$b->id], array_column($rows, 'id'));
    }

    public function test_a_half_built_bundle_is_saved_but_not_ready(): void
    {
        $shop = $this->signIn(Shop::PLATFORM_WOOCOMMERCE);
        $a = $this->book('Atlas', '301');
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        // Three to pick, but only one product listed — and no price.
        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->call('addBundleProduct', $a->id)
            ->set('bundleQuantity', '3')
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::query()->findOrFail($offerId);

        $this->assertSame(UpsellFlowOffer::PRODUCT_BUNDLE, $offer->product_selection_mode);
        $this->assertFalse($offer->isBundle());
        $this->assertNotEmpty($component->instance()->validationIssues(), 'the flow cannot go live on a bundle that cannot be sold');
    }

    public function test_a_shopify_shop_is_not_offered_a_bundle_and_cannot_save_one(): void
    {
        $shop = $this->signIn(Shop::PLATFORM_SHOPIFY);
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId);

        $this->assertFalse($component->instance()->bundleAvailable());

        // A tampered request still cannot store it: the Shopify post-purchase page has no picker.
        $component->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)->call('saveOfferConfig');

        $this->assertSame(UpsellFlowOffer::PRODUCT_SPECIFIC, UpsellFlowOffer::query()->findOrFail($offerId)->product_selection_mode);
    }

    // === Fixtures ===

    private function signIn(string $platform): Shop
    {
        $shop = $this->shop($platform, $platform === Shop::PLATFORM_SHOPIFY ? 'bundle.myshopify.com' : 'bundle.example.com');
        Tenant::set($shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'bundle-'.$platform.'@test.test',
            'password' => bcrypt('password'),
        ]));

        return $shop;
    }

    private function shop(string $platform, string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $platform === Shop::PLATFORM_SHOPIFY ? $domain : null,
            'woocommerce_domain' => $platform === Shop::PLATFORM_WOOCOMMERCE ? $domain : null,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => $platform,
        ]);
    }

    private function book(string $title, string $externalId): Product
    {
        $product = Product::create([
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => $title,
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => Product::ONLINE_PUBLISHED,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'external_variant_id' => $externalId,
            'title' => 'Default',
            'price' => 59,
            'position' => 0,
        ]);

        return $product->fresh();
    }

    private function flow(Shop $shop): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'Books', 'priority' => 1]);
        $flow->shop_id = $shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        UpsellFlowTrigger::create(['flow_id' => $flow->id, 'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT]);
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => '',
            'offer_variant_gid' => '',
            'offer_title' => 'Books',
            'base_price' => 0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'headline' => 'Books for the road',
            'accept_cta' => 'Add to my order',
            'position' => 0,
        ]);

        return $flow->fresh();
    }
}
