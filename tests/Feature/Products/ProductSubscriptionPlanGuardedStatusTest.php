<?php

namespace Tests\Feature\Products;

use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Modules\PayPlusShopifyInstallments\Exceptions\IllegalTransitionException;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plan-template status is a guarded state machine (PlanTemplateStatus,
 * draft <-> active): a raw create() cannot set it, transitionTo() enforces the
 * allowed table, and every accepted move writes a Timeline (activity_events) row.
 */
final class ProductSubscriptionPlanGuardedStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_raw_create_cannot_set_status_and_defaults_to_draft(): void
    {
        [$shop, $product] = $this->makeProduct();
        Tenant::set($shop);

        // Pass status=active in mass-assignment — it is guarded, so the template is
        // born `draft` regardless (the DB default), proving create() can't bypass
        // the state machine.
        $plan = ProductSubscriptionPlan::create([
            'product_id' => $product->id,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'status' => PlanTemplateStatus::ACTIVE->value,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
        ]);

        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->refresh()->status);
    }

    public function test_transition_to_active_succeeds_and_records_timeline(): void
    {
        [$shop, $product] = $this->makeProduct();
        Tenant::set($shop);

        $plan = ProductSubscriptionPlan::create([
            'product_id' => $product->id,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
        ]);
        // status is guarded — create() leaves it at the DB default 'draft'. Reload
        // so the model holds the persisted status before the guarded transition.
        $plan->refresh();

        $plan->transitionTo(PlanTemplateStatus::ACTIVE);

        $this->assertSame(PlanTemplateStatus::ACTIVE, $plan->refresh()->status);

        $event = ActivityEvent::where('kind', 'status_changed')
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('ProductSubscriptionPlan', $event->details['model']);
        $this->assertSame('draft', $event->details['from']);
        $this->assertSame('active', $event->details['to']);
        $this->assertSame($shop->id, $event->shop_id);
    }

    public function test_round_trip_active_back_to_draft_is_legal(): void
    {
        [$shop, $product] = $this->makeProduct();
        Tenant::set($shop);

        $plan = ProductSubscriptionPlan::create([
            'product_id' => $product->id,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
        ]);
        $plan->refresh();

        $plan->transitionTo(PlanTemplateStatus::ACTIVE);
        $plan->transitionTo(PlanTemplateStatus::DRAFT);

        $this->assertSame(PlanTemplateStatus::DRAFT, $plan->refresh()->status);
    }

    public function test_discounted_price_is_server_computed_and_floored(): void
    {
        [$shop, $product] = $this->makeProduct();
        Tenant::set($shop);

        $percent = ProductSubscriptionPlan::create([
            'product_id' => $product->id,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_PERCENT,
            'discount_value' => 10,
        ]);
        $this->assertSame(89.91, $percent->discountedPrice(99.90));

        $fixed = ProductSubscriptionPlan::create([
            'product_id' => $product->id,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_FIXED,
            'discount_value' => 200,
        ]);
        // Over-discount floors at 0 (money law).
        $this->assertSame(0.0, $fixed->discountedPrice(99.90));
    }

    /** @return array{0: Shop, 1: Product} */
    private function makeProduct(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'g.myshopify.com',
            'status' => Shop::STATUS_INSTALLED,
        ]);

        $product = Tenant::run($shop, fn () => Product::create([
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => '7001',
            'title' => 'Guarded product',
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => Product::ONLINE_PUBLISHED,
        ]));

        return [$shop, $product];
    }
}
