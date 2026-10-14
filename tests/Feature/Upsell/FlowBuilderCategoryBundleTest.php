<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Filament\Pages\FlowBuilder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Services\Products\Data\ProductData;
use App\Services\Products\Data\VariantData;
use App\Services\Products\ProductUpserter;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A bundle drawn from a store category instead of a hand-picked list, the grid's tiles per
 * row set for one offer, the higher product cap, and a product search that finds a title
 * from its words.
 *
 * What must never happen: a category or product from another shop reaching the offer, or a
 * category bundle that cannot be completed looking ready.
 */
final class FlowBuilderCategoryBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_catalogue_sync_keeps_each_products_categories(): void
    {
        $this->signIn();
        $upserter = app(ProductUpserter::class);

        $product = $upserter->upsert($this->data('501', 'Right and Left', ['7' => 'Politics', '9' => 'New']), Product::SOURCE_WOOCOMMERCE);
        $this->assertEqualsCanonicalizing(['7', '9'], $product->categories()->pluck('external_category_id')->all());

        // Taken out of "New", "Politics" renamed: one row each, renamed in place.
        $upserter->upsert($this->data('501', 'Right and Left', ['7' => 'Politics & Society']), Product::SOURCE_WOOCOMMERCE);
        $this->assertSame([['7', 'Politics & Society']], $product->categories()->get()->map(fn ($c): array => [$c->external_category_id, $c->name])->all());

        // A source that does not report categories leaves them alone.
        $upserter->upsert($this->data('501', 'Right and Left', null), Product::SOURCE_WOOCOMMERCE);
        $this->assertSame(1, $product->categories()->count());
    }

    public function test_a_merchant_builds_a_bundle_from_a_category(): void
    {
        $shop = $this->signIn();
        $shelf = [$this->book('Cosmos', '303', '7'), $this->book('Atlas', '301', '7'), $this->book('Borders', '302', '7')];
        $this->book('Dunes', '304', '8');
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->set('bundleSource', UpsellFlowOffer::BUNDLE_SOURCE_CATEGORY)
            ->set('bundleCategoryId', '7')
            ->assertSee('Politics (3)')
            ->assertSee('Travel (1)');

        // The drawer shows what the shopper will see: the shelf, by title.
        $this->assertSame(['Atlas', 'Borders', 'Cosmos'], array_column($component->instance()->bundleCategoryRows(), 'title'));

        $component->set('bundleQuantity', '2')
            ->set('bundlePrice', '100')
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::query()->findOrFail($offerId);

        $this->assertTrue($offer->bundleFromCategory());
        $this->assertSame('7', $offer->bundle_category_id);
        $this->assertSame('Politics', $offer->bundle_category_name);
        $this->assertTrue($offer->bundleIsSellable());
        $this->assertSame(['Atlas', 'Borders', 'Cosmos'], $offer->bundleProducts()->pluck('title')->all());
        $this->assertTrue($offer->acceptsSelection([$shelf[0]->id, $shelf[1]->id]));
    }

    public function test_a_category_is_only_ever_one_of_this_shops(): void
    {
        $shop = $this->signIn();
        $this->book('Atlas', '301', '7');
        Tenant::run($this->shop('rival.example.com'), fn (): Product => $this->book('Foreign', '999', '66', 'Rival shelf'));
        Tenant::set($shop);
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->assertDontSee('Rival shelf')
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->set('bundleSource', UpsellFlowOffer::BUNDLE_SOURCE_CATEGORY)
            ->set('bundleCategoryId', '66')
            ->set('bundleQuantity', '1')
            ->set('bundlePrice', '50')
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::query()->findOrFail($offerId);
        $this->assertNull($offer->bundle_category_id, 'a category this shop does not have is never stored');
        $this->assertFalse($offer->isBundle());
    }

    public function test_a_category_with_fewer_books_than_the_pick_is_not_ready(): void
    {
        $shop = $this->signIn();
        $this->book('Atlas', '301', '7');
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->set('bundleSource', UpsellFlowOffer::BUNDLE_SOURCE_CATEGORY)
            ->set('bundleCategoryId', '7')
            ->set('bundleQuantity', '4')
            ->set('bundlePrice', '100')
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::query()->findOrFail($offerId);
        $this->assertFalse($offer->bundleIsSellable());
        $this->assertSame(
            [__('upsell.admin.builder.error.bundle_incomplete', ['offer' => 'Books'])],
            $component->instance()->validationIssues(),
        );
    }

    public function test_an_offer_sets_its_own_tiles_per_row_and_can_go_back_to_the_shops(): void
    {
        $shop = $this->signIn();
        $a = $this->book('Atlas', '301');
        $flow = $this->flow($shop);
        $offerId = $flow->offers()->first()->id;

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE)
            ->call('addBundleProduct', $a->id)
            ->set('bundleQuantity', '1')
            ->set('bundlePrice', '30')
            ->set('bundleGridColumns', '5')
            ->call('saveOfferConfig');

        $this->assertSame(5, UpsellFlowOffer::query()->findOrFail($offerId)->bundleGridColumns());

        // Not one of the grid's choices: back to the shop's setting, never stored as asked.
        $component->call('openOfferConfig', $offerId)->set('bundleGridColumns', '99')->call('saveOfferConfig');
        $this->assertNull(UpsellFlowOffer::query()->findOrFail($offerId)->bundleGridColumns());
    }

    public function test_a_bundle_lists_thirty_books(): void
    {
        $shop = $this->signIn();
        $flow = $this->flow($shop);
        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $flow->offers()->first()->id)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_BUNDLE);

        foreach (range(1, 30) as $n) {
            $component->call('addBundleProduct', $this->book('Book '.$n, (string) (600 + $n))->id);
        }

        $this->assertCount(30, $component->instance()->bundleProductRows());
    }

    public function test_the_search_finds_a_title_from_its_words(): void
    {
        $shop = $this->signIn();
        // A non-breaking space and a Hebrew gershayim, as WordPress titles often have.
        $book = $this->book("מדוע אתה מצביע ימין\u{00A0}ומקבל שמאל", '701');
        $acronym = $this->book('ספר צה״ל', '702');
        $flow = $this->flow($shop);

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])->call('openOfferConfig', $flow->offers()->first()->id);

        foreach (['מדוע אתה מצביע ימין ומקבל שמאל', 'ימין שמאל', 'שמאל  מדוע'] as $term) {
            $ids = array_column($component->set('productSearch', $term)->instance()->offerPickerResults(), 'id');
            $this->assertContains($book->id, $ids, "\"{$term}\" finds the book");
        }

        $ids = array_column($component->set('productSearch', 'צה"ל')->instance()->offerPickerResults(), 'id');
        $this->assertContains($acronym->id, $ids, 'an ASCII quote finds the gershayim');

        $ids = array_column($component->set('productSearch', 'ימין ימני')->instance()->offerPickerResults(), 'id');
        $this->assertNotContains($book->id, $ids, 'every word must be in the title');
    }

    /** @param array<string, string>|null $categories */
    private function data(string $externalId, string $title, ?array $categories): ProductData
    {
        return new ProductData(
            externalId: $externalId,
            title: $title,
            status: Product::STATUS_ACTIVE,
            variants: [new VariantData(externalId: $externalId, title: null, sku: null, price: '59', position: 0)],
            categories: $categories,
        );
    }

    private function signIn(): Shop
    {
        $shop = $this->shop('category.example.com');
        Tenant::set($shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'category-bundle@test.test',
            'password' => bcrypt('password'),
        ]));

        return $shop;
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function book(string $title, string $externalId, ?string $category = null, ?string $categoryName = null): Product
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

        if ($category !== null) {
            ProductCategory::create([
                'product_id' => $product->id,
                'external_category_id' => $category,
                'name' => $categoryName ?? ($category === '7' ? 'Politics' : 'Travel'),
            ]);
        }

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
