<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\SellingPlanService;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Publishing a merchant plan template to Shopify as a SELLING PLAN — the step
 * that makes a product subscribable at checkout on the Shopify-Payments rail
 * (without it, no contract can ever be created, so nothing can bill).
 *
 *   1. the template's cadence + discount reach Shopify in Shopify's vocabulary;
 *   2. the returned group/plan gids are stored — that is what "live" means;
 *   3. re-publishing retires the previous group instead of stacking duplicates;
 *   4. the rail resolves per plan: an override wins, else the shop's choice.
 */
final class SellingPlanPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_publishing_sends_the_templates_cadence_and_stores_the_gids(): void
    {
        $shop = $this->shop();
        $template = $this->template($shop, ['billing_frequency' => 'quarterly', 'interval_count' => 2]);
        $recorder = $this->fakeCreate();

        $result = Tenant::run($shop, fn (): array => app(SellingPlanService::class)->publishTemplate($shop, $template));

        // quarterly (3 months) x interval_count 2 = every 6 months, in Shopify's shape.
        $input = $recorder->graphqlCalls[0]['variables']['input'] ?? [];
        $policy = $input['sellingPlansToCreate'][0]['billingPolicy']['recurring'] ?? [];
        $this->assertSame('MONTH', $policy['interval'] ?? null);
        $this->assertSame(6, $policy['intervalCount'] ?? null);

        $this->assertSame('gid://shopify/SellingPlanGroup/1', $result['group_gid']);
        $this->assertSame('gid://shopify/SellingPlan/11', $result['plan_gid']);

        $fresh = $template->fresh();
        $this->assertTrue($fresh->isPublishedToShopify());
        $this->assertNotNull($fresh->shopify_synced_at);
    }

    public function test_publishing_tags_the_product_so_shopifys_own_admin_shows_it(): void
    {
        $shop = $this->shop();
        $template = $this->template($shop);
        $recorder = $this->fakeCreate();
        // tagsAdd + metafieldsSet follow the create on the same fake client.
        $recorder->graphqlResponses[] = ['data' => ['tagsAdd' => ['userErrors' => []]]];
        $recorder->graphqlResponses[] = ['data' => ['metafieldsSet' => ['userErrors' => []]]];

        Tenant::run($shop, fn () => app(SellingPlanService::class)->publishTemplate($shop, $template));

        $tagCall = collect($recorder->graphqlCalls)
            ->first(fn (array $c): bool => str_contains($c['query'] ?? '', 'tagsAdd'));
        $this->assertNotNull($tagCall, 'The product must be tagged in Shopify.');
        $this->assertSame(['LETS Subscription'], $tagCall['variables']['tags'] ?? null);
        $this->assertStringStartsWith('gid://shopify/Product/', $tagCall['variables']['id'] ?? '');
    }

    public function test_a_failed_tag_write_never_undoes_a_live_selling_plan(): void
    {
        $shop = $this->shop();
        $template = $this->template($shop);

        // The create succeeds; the cosmetic tag call then throws.
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [
            ['data' => ['sellingPlanGroupCreate' => [
                'sellingPlanGroup' => [
                    'id' => 'gid://shopify/SellingPlanGroup/1',
                    'sellingPlans' => ['edges' => [['node' => ['id' => 'gid://shopify/SellingPlan/11']]]],
                ],
                'userErrors' => [],
            ]]],
        ];
        $recorder->graphqlThrowsAfter = 1;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $result = Tenant::run($shop, fn (): array => app(SellingPlanService::class)->publishTemplate($shop, $template));

        // The plan is live and recorded; only the tag is missing.
        $this->assertSame('gid://shopify/SellingPlan/11', $result['plan_gid']);
        $this->assertTrue($template->fresh()->isPublishedToShopify());
    }

    public function test_a_percentage_discount_is_sent_as_shopifys_pricing_policy(): void
    {
        $shop = $this->shop();
        $template = $this->template($shop, [
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_PERCENT,
            'discount_value' => 15,
        ]);
        $recorder = $this->fakeCreate();

        Tenant::run($shop, fn () => app(SellingPlanService::class)->publishTemplate($shop, $template));

        $plan = $recorder->graphqlCalls[0]['variables']['input']['sellingPlansToCreate'][0] ?? [];
        $adjustment = $plan['pricingPolicies'][0]['fixed'] ?? [];
        $this->assertSame('PERCENTAGE', $adjustment['adjustmentType'] ?? null);
        $this->assertSame(15.0, $adjustment['adjustmentValue']['percentage'] ?? null);
    }

    public function test_republishing_retires_the_previous_group(): void
    {
        $shop = $this->shop();
        $template = $this->template($shop);
        $template->forceFill([
            'shopify_selling_plan_group_gid' => 'gid://shopify/SellingPlanGroup/OLD',
            'shopify_selling_plan_gid' => 'gid://shopify/SellingPlan/OLD',
        ])->save();

        $recorder = $this->fakeCreate();
        $recorder->graphqlResponses[] = ['data' => ['sellingPlanGroupDelete' => [
            'deletedSellingPlanGroupId' => 'gid://shopify/SellingPlanGroup/OLD', 'userErrors' => [],
        ]]];

        Tenant::run($shop, fn () => app(SellingPlanService::class)->publishTemplate($shop, $template));

        $deleted = collect($recorder->graphqlCalls)
            ->contains(fn (array $call): bool => ($call['variables']['id'] ?? null) === 'gid://shopify/SellingPlanGroup/OLD');
        $this->assertTrue($deleted, 'The superseded group must be deleted, never left stacked.');
        $this->assertSame('gid://shopify/SellingPlanGroup/1', $template->fresh()->shopify_selling_plan_group_gid);
    }

    public function test_a_one_time_template_is_never_published(): void
    {
        $shop = $this->shop();
        $template = $this->template($shop, ['plan_type' => ProductSubscriptionPlan::TYPE_ONE_TIME]);
        $this->fakeCreate();

        $this->expectException(\RuntimeException::class);
        Tenant::run($shop, fn () => app(SellingPlanService::class)->publishTemplate($shop, $template));
    }

    public function test_the_rail_resolves_per_plan_with_the_override_winning(): void
    {
        $shop = $this->shop(Shop::RAIL_PAYPLUS);
        $inherits = $this->template($shop);
        $overrides = $this->template($shop, ['billing_rail' => Shop::RAIL_SHOPIFY_PAYMENTS]);

        // No override → the store's engine (PayPlus here).
        $this->assertSame(Shop::RAIL_PAYPLUS, $inherits->effectiveRail($shop));
        $this->assertFalse($inherits->usesShopifyPayments($shop));

        // Override → this product bills on Shopify Payments even on a PayPlus store.
        $this->assertSame(Shop::RAIL_SHOPIFY_PAYMENTS, $overrides->effectiveRail($shop));
        $this->assertTrue($overrides->usesShopifyPayments($shop));
    }

    // === Helpers ===

    private function shop(string $rail = Shop::RAIL_SHOPIFY_PAYMENTS): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'plans.myshopify.com',
            'name' => 'Plans',
            'status' => Shop::STATUS_INSTALLED,
            'subscription_rail' => $rail,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function template(Shop $shop, array $overrides = []): ProductSubscriptionPlan
    {
        return Tenant::run($shop, function () use ($shop, $overrides): ProductSubscriptionPlan {
            // A fresh upstream product per template — (shop, source, external_id) is unique.
            $externalId = (string) (5001 + Product::query()->count());

            $product = new Product();
            $product->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => $externalId,
                'title' => 'Coffee',
                'status' => Product::STATUS_ACTIVE,
            ])->save();

            $plan = new ProductSubscriptionPlan();
            $plan->forceFill(array_merge([
                'shop_id' => (int) $shop->getKey(),
                'product_id' => (int) $product->getKey(),
                'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
                'plan_name' => 'Monthly coffee',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
                'discount_value' => 0,
                'status' => ProductSubscriptionPlan::STATUS_ACTIVE,
                'position' => 1,
            ], $overrides))->save();

            return $plan->fresh();
        });
    }

    private function fakeCreate(): RecordingShopifyClient
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [
            ['data' => ['sellingPlanGroupCreate' => [
                'sellingPlanGroup' => [
                    'id' => 'gid://shopify/SellingPlanGroup/1',
                    'sellingPlans' => ['edges' => [['node' => ['id' => 'gid://shopify/SellingPlan/11']]]],
                ],
                'userErrors' => [],
            ]]],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        return $recorder;
    }
}
