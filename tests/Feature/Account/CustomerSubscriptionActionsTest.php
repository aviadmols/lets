<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shopper acting on their own subscription. The two properties worth pinning
 * are the ones a bug would quietly break: they can only ever reach their OWN
 * plan, and they can name a product but never a price.
 */
final class CustomerSubscriptionActionsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private CustomerSubscriptionActions $actions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'acct.myshopify.com',
            'name' => 'Acct',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);

        $this->actions = app(CustomerSubscriptionActions::class);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_shopper_cannot_touch_another_shoppers_plan(): void
    {
        $mine = $this->plan('7', email: 'dana@example.com');
        $theirs = $this->plan('8', email: 'yossi@example.com');

        $outcome = $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_PAUSE, (string) $theirs->public_id);

        // "invalid" and not "forbidden": a Forbidden would confirm the plan exists.
        $this->assertSame(CustomerSubscriptionActions::RESULT_INVALID, $outcome['result']);
        $this->assertSame(PlanStatus::ACTIVE, $theirs->refresh()->status);
        $this->assertSame(PlanStatus::ACTIVE, $mine->refresh()->status);
    }

    /**
     * The other half of the same rule. A plan bought as a guest carries only an
     * email; once that shopper registers they arrive under a WordPress user id
     * the plan has never seen. Matching the platform-asserted email is what
     * reunites them — and it is safe precisely because both WordPress and Shopify
     * enforce one account per address.
     */
    public function test_a_plan_bought_as_a_guest_is_reachable_after_they_register(): void
    {
        $plan = $this->plan(customerRef: null, email: 'dana@example.com');

        $outcome = $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_PAUSE, (string) $plan->public_id);

        $this->assertSame(CustomerSubscriptionActions::RESULT_OK, $outcome['result']);
        $this->assertSame(PlanStatus::PAUSED, $plan->refresh()->status);
    }

    public function test_a_plan_on_another_shop_is_invisible(): void
    {
        $other = Shop::create([
            'shopify_domain' => 'other-acct.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $foreign = Tenant::run($other, fn (): InstallmentPlan => $this->plan('7', $other));

        $outcome = $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_CANCEL, (string) $foreign->public_id);

        $this->assertSame(CustomerSubscriptionActions::RESULT_INVALID, $outcome['result']);
        $this->assertSame(PlanStatus::ACTIVE, Tenant::run($other, fn () => $foreign->refresh()->status));
    }

    public function test_an_unidentified_visitor_reaches_nothing(): void
    {
        $plan = $this->plan('7');

        $outcome = $this->actions->perform(
            AccountVisitor::make($this->shop, null, AccountVisitor::SOURCE_WOOCOMMERCE),
            CustomerSubscriptionActions::ACTION_CANCEL,
            (string) $plan->public_id,
        );

        $this->assertSame(CustomerSubscriptionActions::RESULT_INVALID, $outcome['result']);
    }

    public function test_pause_and_resume_round_trip(): void
    {
        $plan = $this->plan('7');

        $this->assertSame(
            CustomerSubscriptionActions::RESULT_OK,
            $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_PAUSE, (string) $plan->public_id)['result'],
        );
        $this->assertSame(PlanStatus::PAUSED, $plan->refresh()->status);

        $this->assertSame(
            CustomerSubscriptionActions::RESULT_OK,
            $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_RESUME, (string) $plan->public_id)['result'],
        );
        $this->assertSame(PlanStatus::ACTIVE, $plan->refresh()->status);
    }

    public function test_pausing_twice_is_a_no_op_success(): void
    {
        $plan = $this->plan('7');
        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_PAUSE, (string) $plan->public_id);

        // A double-tap or a resubmitted form must not be an error the shopper sees.
        $this->assertSame(
            CustomerSubscriptionActions::RESULT_OK,
            $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_PAUSE, (string) $plan->public_id)['result'],
        );
    }

    public function test_a_merchant_who_disabled_self_service_is_obeyed(): void
    {
        $settings = MerchantBillingSettings::current();
        $settings->allow_customer_pause = false;
        $settings->allow_customer_cancel = false;
        $settings->save();

        $plan = $this->plan('7');

        foreach ([CustomerSubscriptionActions::ACTION_PAUSE, CustomerSubscriptionActions::ACTION_CANCEL] as $action) {
            $this->assertSame(
                CustomerSubscriptionActions::RESULT_NOT_ALLOWED,
                $this->actions->perform($this->visitor('7'), $action, (string) $plan->public_id)['result'],
            );
        }

        $this->assertSame(PlanStatus::ACTIVE, $plan->refresh()->status);
    }

    public function test_skip_moves_the_next_charge_one_interval_forward(): void
    {
        $plan = $this->plan('7');
        $before = $plan->next_charge_at->copy();

        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_SKIP, (string) $plan->public_id);

        $this->assertSame(
            $before->copy()->addMonth()->toDateString(),
            $plan->refresh()->next_charge_at->toDateString(),
        );
    }

    public function test_a_past_reschedule_date_is_refused(): void
    {
        $plan = $this->plan('7');
        $before = $plan->next_charge_at->toDateString();

        // A date in the past would charge on the spot.
        $outcome = $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_RESCHEDULE, (string) $plan->public_id, [
            'date' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(CustomerSubscriptionActions::RESULT_INVALID, $outcome['result']);
        $this->assertSame($before, $plan->refresh()->next_charge_at->toDateString());
    }

    public function test_a_reschedule_past_the_horizon_is_refused(): void
    {
        $plan = $this->plan('7');

        // A year and a half out is a cancellation the shopper did not think they
        // were making.
        $outcome = $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_RESCHEDULE, (string) $plan->public_id, [
            'date' => now()->addDays(CustomerSubscriptionActions::MAX_RESCHEDULE_DAYS + 30)->toDateString(),
        ]);

        $this->assertSame(CustomerSubscriptionActions::RESULT_INVALID, $outcome['result']);
    }

    public function test_a_customer_item_edit_is_priced_by_the_catalog_and_ignores_a_sent_price(): void
    {
        $this->catalogProduct('4242', 50.0);
        $plan = $this->plan('7');

        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_ITEMS, (string) $plan->public_id, [
            'line_items' => [
                // The hostile field. SubscriptionEditService WOULD honour it for an
                // admin; from this surface it must never arrive at all.
                ['product_id' => '4242', 'quantity' => 2, 'unit_price' => 0.01],
            ],
        ]);

        $override = $plan->refresh()->nextOrderOverride();

        $this->assertNotNull($override);
        $this->assertSame(100.0, round((float) $override['amount'], 2));
        $this->assertSame(50.0, round((float) $override['line_items'][0]['unit_price'], 2));
    }

    public function test_an_unknown_product_is_dropped_rather_than_priced_at_zero(): void
    {
        $this->catalogProduct('4242', 50.0);
        $plan = $this->plan('7');

        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_ITEMS, (string) $plan->public_id, [
            'line_items' => [
                ['product_id' => '4242', 'quantity' => 1],
                ['product_id' => '999999', 'quantity' => 5],
            ],
        ]);

        $override = $plan->refresh()->nextOrderOverride();

        $this->assertCount(1, $override['line_items']);
        $this->assertSame(50.0, round((float) $override['amount'], 2));
    }

    public function test_quantity_is_clamped_so_one_tap_cannot_order_a_thousand(): void
    {
        $this->catalogProduct('4242', 50.0);
        $plan = $this->plan('7');

        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_ITEMS, (string) $plan->public_id, [
            'line_items' => [['product_id' => '4242', 'quantity' => 9999]],
        ]);

        $override = $plan->refresh()->nextOrderOverride();

        $this->assertSame(CustomerSubscriptionActions::MAX_QUANTITY, (int) $override['line_items'][0]['quantity']);
    }

    public function test_an_empty_item_set_clears_the_override(): void
    {
        $this->catalogProduct('4242', 50.0);
        $plan = $this->plan('7');

        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_ITEMS, (string) $plan->public_id, [
            'line_items' => [['product_id' => '4242', 'quantity' => 1]],
        ]);
        $this->assertNotNull($plan->refresh()->nextOrderOverride());

        // Sending nothing is how a shopper undoes their own edit.
        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_ITEMS, (string) $plan->public_id, [
            'line_items' => [],
        ]);
        $this->assertNull($plan->refresh()->nextOrderOverride());
    }

    public function test_a_paused_plan_offers_resume_but_not_skip(): void
    {
        $plan = $this->plan('7');
        $this->actions->perform($this->visitor('7'), CustomerSubscriptionActions::ACTION_PAUSE, (string) $plan->public_id);

        $available = $this->actions->availableFor($plan->refresh());

        $this->assertTrue($available[CustomerSubscriptionActions::ACTION_RESUME]);
        $this->assertFalse($available[CustomerSubscriptionActions::ACTION_SKIP]);
        $this->assertFalse($available[CustomerSubscriptionActions::ACTION_PAUSE]);
    }

    // === Fixtures ===

    private function visitor(string $ref): AccountVisitor
    {
        return AccountVisitor::make($this->shop, $ref, AccountVisitor::SOURCE_WOOCOMMERCE, 'dana@example.com');
    }

    private function plan(?string $customerRef = '7', ?Shop $shop = null, string $email = 'dana@example.com'): InstallmentPlan
    {
        $shop ??= $this->shop;

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.($customerRef ?? 'guest').'-'.$shop->getKey().'-'.uniqid(),
            'external_customer_id' => $customerRef,
            'customer_email' => $email,
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 89,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ])->save();

        return $plan;
    }

    private function catalogProduct(string $externalId, float $price): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $this->shop->getKey(),
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => 'Coffee',
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $this->shop->getKey(),
            'product_id' => $product->getKey(),
            'external_variant_id' => $externalId.'-v',
            'price' => $price,
            'position' => 0,
        ])->save();
    }
}
