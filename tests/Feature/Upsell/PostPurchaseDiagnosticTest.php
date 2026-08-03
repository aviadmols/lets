<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\PostPurchaseDiagnostic;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The diagnostic exists because every link in the post-purchase chain fails
 * SILENTLY — right for shoppers, useless for a merchant staring at a builder
 * that looks finished. Each test here pins one named cause.
 */
final class PostPurchaseDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'diag.myshopify.com',
            'name' => 'Diag',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_draft_flow_is_named_as_the_reason(): void
    {
        $flow = $this->flow(UpsellFlowStatus::DRAFT);
        $this->completeOffer($flow);
        $this->trigger($flow, UpsellFlowTrigger::MATCH_ANY_PRODUCT);

        $this->assertSame(
            PostPurchaseDiagnostic::PROBLEM,
            $this->check($flow, PostPurchaseDiagnostic::CHECK_FLOW_ACTIVE)['status'],
        );
    }

    public function test_an_incomplete_offer_lists_exactly_what_is_missing(): void
    {
        $flow = $this->flow();
        // The shape "Create new" produces: nothing filled in.
        $offer = new UpsellFlowOffer;
        $offer->forceFill([
            'shop_id' => $this->shop->getKey(),
            'flow_id' => $flow->getKey(),
            'offer_product_gid' => '',
            'offer_variant_gid' => '',
            'base_price' => 0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'position' => 0,
        ])->save();

        $check = $this->check($flow, PostPurchaseDiagnostic::CHECK_OFFER_COMPLETE);

        $this->assertSame(PostPurchaseDiagnostic::PROBLEM, $check['status']);
        // Naming the fields is the whole point — "not activatable" is another dead end.
        $this->assertStringContainsString('product', $check['detail']);
        $this->assertStringContainsString('price', $check['detail']);
        $this->assertStringContainsString('headline', $check['detail']);
        $this->assertStringContainsString('accept_cta', $check['detail']);
    }

    public function test_a_collection_trigger_is_reported_as_unusable_on_this_rail(): void
    {
        // The post-purchase token carries line items and a total — no
        // collections, no tags. A collection rule can never match here.
        $flow = $this->flow();
        $this->completeOffer($flow);
        $this->trigger($flow, UpsellFlowTrigger::MATCH_COLLECTION);

        $check = $this->check($flow, PostPurchaseDiagnostic::CHECK_TRIGGER);

        $this->assertSame(PostPurchaseDiagnostic::PROBLEM, $check['status']);
        $this->assertSame('unsupported_type', $check['detail']);
    }

    public function test_a_product_trigger_is_usable(): void
    {
        $flow = $this->flow();
        $this->completeOffer($flow);
        $this->trigger($flow, UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT);

        $this->assertSame(
            PostPurchaseDiagnostic::OK,
            $this->check($flow, PostPurchaseDiagnostic::CHECK_TRIGGER)['status'],
        );
    }

    public function test_an_unsynced_product_cannot_resolve_a_variant(): void
    {
        $flow = $this->flow();
        // A product gid the catalog has never seen ⇒ no variant to add.
        $this->completeOffer($flow, productGid: 'gid://shopify/Product/999999', variantGid: '');

        $check = $this->check($flow, PostPurchaseDiagnostic::CHECK_VARIANT);

        $this->assertSame(PostPurchaseDiagnostic::PROBLEM, $check['status']);
        $this->assertSame('unresolved', $check['detail']);
    }

    public function test_a_synced_product_resolves_through_its_primary_variant(): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $this->shop->getKey(),
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => '4242',
            'title' => 'Candle',
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $this->shop->getKey(),
            'product_id' => $product->getKey(),
            'external_variant_id' => '777',
            'price' => 50,
            'position' => 0,
        ])->save();

        $flow = $this->flow();
        $this->completeOffer($flow, productGid: 'gid://shopify/Product/4242', variantGid: '');

        $this->assertSame(
            PostPurchaseDiagnostic::OK,
            $this->check($flow, PostPurchaseDiagnostic::CHECK_VARIANT)['status'],
        );
    }

    public function test_the_two_shopify_steps_are_reported_as_unverifiable(): void
    {
        $flow = $this->flow(UpsellFlowStatus::ACTIVE);
        $this->completeOffer($flow);
        $this->trigger($flow, UpsellFlowTrigger::MATCH_ANY_PRODUCT);

        // We must never claim to have checked what only Shopify knows.
        foreach ([PostPurchaseDiagnostic::CHECK_DEPLOYED, PostPurchaseDiagnostic::CHECK_SELECTED] as $key) {
            $this->assertSame(PostPurchaseDiagnostic::UNKNOWN, $this->check($flow, $key)['status']);
        }
    }

    public function test_a_healthy_flow_reports_no_problem(): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $this->shop->getKey(),
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => '4242',
            'title' => 'Candle',
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $this->shop->getKey(),
            'product_id' => $product->getKey(),
            'external_variant_id' => '777',
            'price' => 50,
            'position' => 0,
        ])->save();

        $flow = $this->flow(UpsellFlowStatus::ACTIVE);
        $this->completeOffer($flow, productGid: 'gid://shopify/Product/4242');
        $this->trigger($flow, UpsellFlowTrigger::MATCH_ANY_PRODUCT);

        $this->assertFalse(app(PostPurchaseDiagnostic::class)->hasProblem($this->shop, $flow));
    }

    // === Fixtures ===

    private function flow(UpsellFlowStatus $status = UpsellFlowStatus::DRAFT): UpsellFlow
    {
        $flow = new UpsellFlow;
        $flow->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => 'Flow',
            'priority' => 0,
            'status' => $status->value,
        ])->save();

        return $flow;
    }

    private function completeOffer(UpsellFlow $flow, string $productGid = 'gid://shopify/Product/4242', string $variantGid = 'gid://shopify/ProductVariant/777'): void
    {
        $offer = new UpsellFlowOffer;
        $offer->forceFill([
            'shop_id' => $this->shop->getKey(),
            'flow_id' => $flow->getKey(),
            'offer_product_gid' => $productGid,
            'offer_variant_gid' => $variantGid,
            'base_price' => 50,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'headline' => 'Add a candle',
            'accept_cta' => 'Add it',
            'position' => 0,
        ])->save();
    }

    private function trigger(UpsellFlow $flow, string $matchType): void
    {
        $trigger = new UpsellFlowTrigger;
        $trigger->forceFill([
            'shop_id' => $this->shop->getKey(),
            'flow_id' => $flow->getKey(),
            'match_type' => $matchType,
        ])->save();
    }

    /** @return array{key: string, status: string, detail: ?string} */
    private function check(UpsellFlow $flow, string $key): array
    {
        foreach (app(PostPurchaseDiagnostic::class)->run($this->shop, $flow) as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        $this->fail("Diagnostic did not report a [{$key}] check.");
    }
}
