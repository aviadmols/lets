<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Lifecycle\SubscriptionEditService;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\PlatformContext;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W25 — editing the NEXT charge (SubscriptionEditService): products are re-priced server-side from
 * the synced catalog (foreign ids dropped, fail-closed), the date + amount changes are written to a
 * `plan_edited` Timeline row, an empty set clears the override, and the change is attributed to the
 * acting user (admin:{id} for a merchant, platform_admin:{id} for an entered platform admin).
 */
final class SubscriptionEditServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private InstallmentPlan $plan;

    private SubscriptionEditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'edit-svc.example.com',
            'name' => 'Edit Svc',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->service = app(SubscriptionEditService::class);

        // A synced catalog product priced ₪25.
        $this->makeProduct('2670', 'Coffee bag', 25.00);

        $this->plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'installment_amount' => 49.90,
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'currency' => 'ILS',
            'next_charge_at' => now()->addDays(10),
        ]);
        $this->plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();
        $this->plan->refresh();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_builds_a_server_priced_override_and_audits_the_edit(): void
    {
        $this->actingAsMerchant();
        $newDate = now()->addDays(3)->toDateString();

        $this->service->editNextCharge($this->plan, [
            'next_charge_at' => $newDate,
            // Client sends a bogus unit_price; the merchant-set price is honoured (audited admin
            // decision) — but the PRODUCT must exist in the tenant catalog to count at all.
            'line_items' => [['product_id' => '2670', 'quantity' => 3, 'unit_price' => 20.00]],
        ]);

        $override = $this->plan->fresh()->nextOrderOverride();
        $this->assertNotNull($override);
        $this->assertSame(2670, $override['line_items'][0]['product_id']);
        $this->assertEqualsWithDelta(60.00, $override['amount'], 0.001); // 3 × 20
        $this->assertSame($newDate, $this->plan->fresh()->next_charge_at->toDateString());

        $event = ActivityEvent::where('kind', 'plan_edited')->where('plan_id', $this->plan->id)->sole();
        $this->assertEqualsWithDelta(60.00, (float) data_get($event->details, 'changed.amount.to'), 0.001);
        $this->assertSame($newDate, data_get($event->details, 'changed.next_charge_at.to'));
    }

    public function test_a_foreign_product_is_dropped_fail_closed(): void
    {
        $this->actingAsMerchant();

        $this->service->editNextCharge($this->plan, [
            'line_items' => [
                ['product_id' => '2670', 'quantity' => 1],   // real → ₪25 (catalog price)
                ['product_id' => '999999', 'quantity' => 5], // not in the catalog → dropped
            ],
        ]);

        $override = $this->plan->fresh()->nextOrderOverride();
        $this->assertCount(1, $override['line_items']);
        $this->assertEqualsWithDelta(25.00, $override['amount'], 0.001);
    }

    public function test_an_empty_set_clears_the_override(): void
    {
        $this->actingAsMerchant();
        // Seed an override, then clear it.
        $this->plan->forceFill(['meta' => ['next_order' => [
            'line_items' => [['product_id' => 2670, 'name' => 'x', 'quantity' => 1, 'unit_price' => 25.0]],
            'amount' => 25.0,
        ]]])->save();

        $this->service->editNextCharge($this->plan, ['line_items' => []]);

        $this->assertNull($this->plan->fresh()->nextOrderOverride());
    }

    public function test_a_merchant_edit_is_attributed_to_that_user(): void
    {
        $user = $this->actingAsMerchant();

        $this->service->editNextCharge($this->plan, [
            'line_items' => [['product_id' => '2670', 'quantity' => 1]],
        ]);

        $event = ActivityEvent::where('kind', 'plan_edited')->sole();
        $this->assertSame(PlatformContext::ADMIN_PREFIX.$user->id, $event->actor);
    }

    public function test_a_platform_admin_entered_edit_is_attributed_to_the_platform(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);
        PlatformContext::enter($this->shop->id);

        $this->service->editNextCharge($this->plan, [
            'line_items' => [['product_id' => '2670', 'quantity' => 1]],
        ]);

        $event = ActivityEvent::where('kind', 'plan_edited')->sole();
        $this->assertSame(PlatformContext::ACTOR_PREFIX.$admin->id, $event->actor);

        PlatformContext::exit();
    }

    private function actingAsMerchant(): User
    {
        $user = User::create([
            'name' => 'Shop Owner',
            'email' => 'owner-'.uniqid().'@edit-svc.test',
            'password' => bcrypt('password'),
            'shop_id' => $this->shop->id,
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function makeProduct(string $externalId, string $title, float $price): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $this->shop->id,
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => $title,
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => 'published',
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'external_variant_id' => $externalId,
            'title' => '',
            'sku' => 'SKU-'.$externalId,
            'price' => $price,
            'position' => 0,
        ])->save();
    }
}
