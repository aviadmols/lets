<?php

namespace Tests\Feature\Shopify;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Shopify\Webhooks\OrderCancelledHandler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelling the order in Shopify must stop the subscription.
 *
 * The handler used to dispatch an event nobody listened for, so the merchant
 * cancelled the order and the customer went on being billed every cycle with
 * nothing anywhere to show something had gone wrong.
 */
final class OrderCancelledStopsPlanTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ORDER_ID = '556677';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'cancel-order.myshopify.com',
            'name' => 'Cancel',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function plan(string $orderId, PlanStatus $status = PlanStatus::ACTIVE): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'shopify_order_id' => $orderId,
            'shopify_customer_id' => 'cust-1',
            'installment_amount' => 49.90,
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'currency' => 'ILS',
            'next_charge_at' => now()->addMonth(),
        ]);
        $plan->forceFill(['status' => $status->value])->save();

        return $plan->fresh();
    }

    private function fire(string $orderId): void
    {
        $event = WebhookEvent::create([
            'shop_id' => $this->shop->getKey(),
            'topic' => 'orders/cancelled',
            'raw_payload' => ['id' => $orderId],
        ]);

        app(OrderCancelledHandler::class)->handle($event);
    }

    public function test_a_cancelled_order_cancels_its_subscription_and_clears_the_schedule(): void
    {
        $plan = $this->plan(self::ORDER_ID);

        $this->fire(self::ORDER_ID);

        $plan->refresh();
        $this->assertSame(PlanStatus::CANCELLED, $plan->status);
        $this->assertNull($plan->next_charge_at, 'Nothing is ever charged for it again.');
    }

    public function test_a_subscription_in_dunning_is_cancelled_too(): void
    {
        $plan = $this->plan(self::ORDER_ID, PlanStatus::AWAITING_PAYMENT);

        $this->fire(self::ORDER_ID);

        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
    }

    public function test_another_orders_subscription_is_left_alone(): void
    {
        $mine = $this->plan(self::ORDER_ID);
        $theirs = $this->plan('999999');

        $this->fire(self::ORDER_ID);

        $this->assertSame(PlanStatus::CANCELLED, $mine->fresh()->status);
        $this->assertSame(PlanStatus::ACTIVE, $theirs->fresh()->status, 'Only the cancelled order is touched.');
    }

    public function test_a_redelivered_webhook_is_harmless(): void
    {
        $plan = $this->plan(self::ORDER_ID);

        $this->fire(self::ORDER_ID);
        $this->fire(self::ORDER_ID);

        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
    }
}
