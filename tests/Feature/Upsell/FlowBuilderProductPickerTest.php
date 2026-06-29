<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\UpsellResolver;
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
 * The searchable PRODUCT PICKER added to the Flow Builder drawers (offer +
 * trigger). Proves: the results are tenant-scoped + honour the 3-char minimum +
 * match on title/sku; selecting a Shopify product stores gid:// product+variant
 * gids and auto-fills base_price/title (discountedPrice() still derives from
 * base_price); a WooCommerce product stores the raw numeric ids; the trigger
 * stores the identifier in the SAME format UpsellResolver matches against
 * PurchaseContext::$purchasedProductGids; and a foreign/nonexistent product id is
 * rejected (no write) — shop_id/status are never taken from input.
 */
final class FlowBuilderProductPickerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('picker-demo.myshopify.com');
        Tenant::set($this->shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'picker@test.test',
            'password' => bcrypt('password'),
        ]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Search: tenant scope + min length + match fields ===

    public function test_picker_results_are_tenant_scoped(): void
    {
        // Our shop has a matching product; another shop has one too.
        $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY);

        $other = $this->makeShop('rival.myshopify.com');
        Tenant::run($other, fn () => $this->makeProduct('Aurora Serum', '9001', Product::SOURCE_SHOPIFY));

        $flow = $this->makeFlow();

        $results = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $flow->offers()->first()->id)
            ->set('productSearch', 'Aurora')
            ->instance()
            ->offerPickerResults();

        // Only THIS shop's product appears — never the rival's identical-named one.
        $this->assertCount(1, $results);
        $this->assertSame('Aurora Serum', $results[0]['title']);
        $this->assertSame('5001', (string) $this->shop->products()->first()->external_id);
    }

    public function test_picker_honours_the_three_char_minimum(): void
    {
        $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY);
        $flow = $this->makeFlow();

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $flow->offers()->first()->id);

        // Two chars → no query, no rows.
        $this->assertCount(0, $component->set('productSearch', 'Au')->instance()->offerPickerResults());
        // Three chars → matches.
        $this->assertCount(1, $component->set('productSearch', 'Aur')->instance()->offerPickerResults());
    }

    public function test_picker_matches_on_sku(): void
    {
        $product = $this->makeProduct('Night Cream', '5002', Product::SOURCE_SHOPIFY);
        $product->variants()->update(['sku' => 'NC-GLOW-01']);

        $flow = $this->makeFlow();

        $results = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $flow->offers()->first()->id)
            ->set('productSearch', 'GLOW')
            ->instance()
            ->offerPickerResults();

        $this->assertCount(1, $results);
        $this->assertSame('Night Cream', $results[0]['title']);
    }

    public function test_picker_excludes_non_active_products(): void
    {
        $this->makeProduct('Draft Balm', '5003', Product::SOURCE_SHOPIFY, status: Product::STATUS_DRAFT);
        $flow = $this->makeFlow();

        $results = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $flow->offers()->first()->id)
            ->set('productSearch', 'Draft')
            ->instance()
            ->offerPickerResults();

        $this->assertCount(0, $results);
    }

    // === Offer drawer: store gids + auto-fill base_price/title ===

    public function test_selecting_a_shopify_product_stores_gids_and_autofills_price_and_title(): void
    {
        $product = $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY, price: 49.90);
        $variant = $product->variants()->first();
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            // Clear the seeded title so the auto-fill takes effect.
            ->set('offerTitle', '')
            ->call('selectOfferProduct', $product->id, $variant->id)
            // Picker auto-filled the editable fields.
            ->assertSet('offerTitle', 'Aurora Serum')
            ->assertSet('offerBasePrice', '49.90')
            ->call('saveOfferConfig')
            ->assertSet('drawerOpen', false);

        $offer = UpsellFlowOffer::findOrFail($offerId);

        // gid:// format for a Shopify shop, derived from external_id / external_variant_id.
        $this->assertSame('gid://shopify/Product/5001', $offer->offer_product_gid);
        $this->assertSame('gid://shopify/ProductVariant/5001-v1', $offer->offer_variant_gid);
        $this->assertSame('Aurora Serum', $offer->offer_title);
        // base_price persisted from the variant; discountedPrice() derives from it.
        $this->assertSame(49.90, (float) $offer->base_price);
        $this->assertSame(49.90, $offer->discountedPrice());
    }

    public function test_selecting_a_woocommerce_product_stores_raw_numeric_ids(): void
    {
        $product = $this->makeProduct('Wax Kit', '7700', Product::SOURCE_WOOCOMMERCE, price: 30.00);
        $variant = $product->variants()->first();
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('offerTitle', '')
            ->call('selectOfferProduct', $product->id, $variant->id)
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::findOrFail($offerId);

        // Woo has no gids — raw numeric external ids are stored.
        $this->assertSame('7700', $offer->offer_product_gid);
        $this->assertSame('7700-v1', $offer->offer_variant_gid);
        $this->assertSame('Wax Kit', $offer->offer_title);
        $this->assertSame(30.00, (float) $offer->base_price);
    }

    public function test_discounted_price_still_derives_from_autofilled_base_price(): void
    {
        $product = $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY, price: 100.00);
        $variant = $product->variants()->first();
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->call('selectOfferProduct', $product->id, $variant->id)
            ->set('discountPercent', 25)
            ->call('saveOfferConfig');

        $offer = UpsellFlowOffer::findOrFail($offerId);
        $this->assertSame(100.00, (float) $offer->base_price);
        // Money truth: 25% off 100 = 75, computed server-side from base_price.
        $this->assertSame(75.00, $offer->discountedPrice());
    }

    public function test_custom_title_is_not_overwritten_by_autofill(): void
    {
        $product = $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY);
        $variant = $product->variants()->first();
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('offerTitle', 'My custom headline')
            ->call('selectOfferProduct', $product->id, $variant->id)
            // Custom title survives the pick.
            ->assertSet('offerTitle', 'My custom headline')
            ->call('saveOfferConfig');

        $this->assertSame('My custom headline', UpsellFlowOffer::findOrFail($offerId)->offer_title);
    }

    // === Trigger drawer: store the identifier the resolver matches ===

    public function test_trigger_picker_stores_identifier_that_resolver_matches_for_shopify(): void
    {
        $product = $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY);
        $flow = $this->makeFlow();
        $triggerId = $flow->triggers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openTriggerConfig')
            ->set('triggerMatchType', UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT)
            ->call('selectTriggerProduct', $product->id)
            ->assertSet('triggerProductGid', 'gid://shopify/Product/5001')
            ->call('saveTriggerConfig')
            ->assertSet('triggerDrawerOpen', false);

        $trigger = UpsellFlowTrigger::findOrFail($triggerId);
        $this->assertSame(UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT, $trigger->match_type);
        $this->assertSame('gid://shopify/Product/5001', $trigger->shopify_product_gid);

        // The stored identifier is EXACTLY what a PurchaseContext for that product
        // carries, so the resolver fires the flow. Activate it first.
        $flow->refresh()->transitionTo(UpsellFlowStatus::ACTIVE);

        $context = new PurchaseContext(
            shopId: $this->shop->id,
            parentOrderId: 'order-1',
            customerRef: 'cust-1',
            orderSubtotal: 120.0,
            purchasedProductGids: ['gid://shopify/Product/5001'],
        );

        $resolution = (new UpsellResolver())->resolve($context);
        $this->assertNotNull($resolution, 'The trigger identifier must match the resolver context.');
        $this->assertSame($flow->id, $resolution->flow->id);
    }

    public function test_trigger_picker_stores_raw_numeric_for_woocommerce(): void
    {
        $product = $this->makeProduct('Wax Kit', '7700', Product::SOURCE_WOOCOMMERCE);
        $flow = $this->makeFlow();
        $triggerId = $flow->triggers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openTriggerConfig')
            ->set('triggerMatchType', UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT)
            ->call('selectTriggerProduct', $product->id)
            ->call('saveTriggerConfig');

        // Woo purchases carry the raw numeric id; the trigger stores the same form.
        $this->assertSame('7700', UpsellFlowTrigger::findOrFail($triggerId)->shopify_product_gid);
    }

    // === Sanitization: a foreign/nonexistent id is rejected ===

    public function test_selecting_a_foreign_product_in_the_offer_is_a_noop(): void
    {
        // A rival shop owns a product; its id must never bind onto our offer.
        $other = $this->makeShop('rival.myshopify.com');
        $foreignId = Tenant::run($other, fn () => $this->makeProduct('Rival Item', '9001', Product::SOURCE_SHOPIFY)->id);

        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;
        $originalGid = $flow->offers()->first()->offer_product_gid;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->call('selectOfferProduct', $foreignId) // foreign id → resolves to null
            ->assertSet('offerProductId', 0)         // nothing selected
            ->call('saveOfferConfig');

        // The offer's product gid is unchanged (the seeded one), never the rival's.
        $offer = UpsellFlowOffer::findOrFail($offerId);
        $this->assertSame($originalGid, $offer->offer_product_gid);
        $this->assertStringNotContainsString('9001', (string) $offer->offer_product_gid);
    }

    public function test_selecting_a_nonexistent_product_in_the_trigger_is_a_noop(): void
    {
        $flow = $this->makeFlow();
        $triggerId = $flow->triggers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openTriggerConfig')
            ->set('triggerMatchType', UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT)
            ->call('selectTriggerProduct', 424242) // does not exist
            ->assertSet('triggerProductId', 0)
            ->assertSet('triggerProductGid', '');
    }

    public function test_save_does_not_take_status_or_shop_id_from_input(): void
    {
        $product = $this->makeProduct('Aurora Serum', '5001', Product::SOURCE_SHOPIFY);
        $variant = $product->variants()->first();
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->call('selectOfferProduct', $product->id, $variant->id)
            ->call('saveOfferConfig');

        // The offer still belongs to THIS shop — shop_id is never written from input.
        $offer = UpsellFlowOffer::findOrFail($offerId);
        $this->assertSame($this->shop->id, $offer->shop_id);
        // The picked product was active and untouched (status not echoed from input).
        $this->assertSame(Product::STATUS_ACTIVE, $product->fresh()->status);
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }

    /** A tenant-scoped Product with one variant ({external_id}-v1) at the given price. */
    private function makeProduct(
        string $title,
        string $externalId,
        string $source,
        string $status = Product::STATUS_ACTIVE,
        float $price = 50.0,
    ): Product {
        $product = Product::create([
            'source' => $source,
            'external_id' => $externalId,
            'title' => $title,
            'status' => $status,
            'online_store_status' => Product::ONLINE_PUBLISHED,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'external_variant_id' => $externalId.'-v1',
            'title' => 'Default',
            'sku' => $externalId.'-SKU',
            'price' => $price,
            'position' => 0,
        ]);

        return $product->fresh();
    }

    /** A draft flow with one any_product trigger + one seeded offer (tenant-bound). */
    private function makeFlow(): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'Picker flow', 'priority' => 1]);
        $flow->shop_id = $this->shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
        ]);
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/1',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/10',
            'offer_title' => 'Seeded offer',
            'base_price' => 20.0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'headline' => 'Add this',
            'accept_cta' => 'Add to my order',
            'position' => 0,
        ]);

        return $flow->fresh();
    }
}
