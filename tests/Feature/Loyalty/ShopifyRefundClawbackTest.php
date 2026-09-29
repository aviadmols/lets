<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\RefundCreatedHandler;
use App\Services\Shopify\Webhooks\WebhookRouter;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A refund issued in Shopify on a PLAIN order takes back the points that order
 * earned through orders/paid — the same clawback the PayPlus-refund path uses,
 * keyed on the Shopify refund id, floored at the balance.
 */
final class ShopifyRefundClawbackTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER = '7001';

    private const ORDER_ID = '880011';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'refund-claw.myshopify.com',
            'name' => 'Refund claw',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'points_per_currency' => 1,
            'join_bonus_points' => 0,
        ])->save();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_router_sends_refunds_create_to_the_handler(): void
    {
        $this->assertInstanceOf(RefundCreatedHandler::class, app(WebhookRouter::class)->handlerFor('refunds/create'));
    }

    public function test_a_partial_shopify_refund_takes_back_its_share_once(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'shopper@example.com');
        $this->orderPaid(self::ORDER_ID, '200.00');
        $this->assertSame(200, (int) $member->refresh()->points_balance);

        $this->refund('r-1', self::ORDER_ID, [['kind' => 'refund', 'status' => 'success', 'amount' => '50.00']]);
        $this->assertSame(150, (int) $member->refresh()->points_balance);

        // Redelivered (or delivered through a second subscription): takes once.
        $this->refund('r-1', self::ORDER_ID, [['kind' => 'refund', 'status' => 'success', 'amount' => '50.00']]);
        $this->assertSame(150, (int) $member->refresh()->points_balance);
        $this->assertSame(1, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_REFUND_CLAWBACK)->count());

        // A second refund of the rest takes the rest, never more.
        $this->refund('r-2', self::ORDER_ID, [['kind' => 'refund', 'status' => 'success', 'amount' => '500.00']]);
        $this->assertSame(0, (int) $member->refresh()->points_balance);
    }

    public function test_spent_points_are_not_turned_into_a_debt(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'shopper@example.com');
        $this->orderPaid(self::ORDER_ID, '100.00');
        app(PointsEngine::class)->deduct($member->refresh(), 90, 'redeem:test');

        $this->refund('r-9', self::ORDER_ID, [['kind' => 'refund', 'status' => 'success', 'amount' => '100.00']]);

        $this->assertSame(0, (int) $member->refresh()->points_balance, 'Floored at zero.');
    }

    public function test_a_restock_only_or_failed_refund_takes_nothing(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'shopper@example.com');
        $this->orderPaid(self::ORDER_ID, '100.00');

        $this->refund('r-3', self::ORDER_ID, []);
        $this->refund('r-4', self::ORDER_ID, [['kind' => 'refund', 'status' => 'failure', 'amount' => '100.00']]);

        $this->assertSame(100, (int) $member->refresh()->points_balance);
    }

    public function test_an_order_charged_through_our_ledger_is_left_to_the_ledger_path(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'shopper@example.com');
        app(PointsEngine::class)->accrue(self::CUSTOMER, 100.0, LoyaltyPointEvent::keyForShopifyOrder(self::ORDER_ID));

        PaymentLedger::query()->create([
            'shopify_customer_id' => self::CUSTOMER,
            'shopify_order_id' => self::ORDER_ID,
            'charge_context' => 'recurring',
            'idempotency_key' => 'recurring:test:1',
            'amount' => 100,
            'currency' => 'ILS',
            'status' => 'succeeded',
        ]);

        $this->refund('r-5', self::ORDER_ID, [['kind' => 'refund', 'status' => 'success', 'amount' => '100.00']]);

        $this->assertSame(100, (int) $member->refresh()->points_balance, 'The ledger refund path owns it.');
    }

    public function test_another_shops_refund_cannot_reach_this_shops_points(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'shopper@example.com');
        $this->orderPaid(self::ORDER_ID, '100.00');

        $other = Shop::create(['shopify_domain' => 'other-claw.myshopify.com', 'name' => 'Other', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::run($other, fn () => $this->refund('r-6', self::ORDER_ID,
            [['kind' => 'refund', 'status' => 'success', 'amount' => '100.00']], $other));

        $this->assertSame(100, (int) $member->refresh()->points_balance);
    }

    private function orderPaid(string $orderId, string $total): void
    {
        Event::dispatch('shopify.order.paid', [[
            'shop_id' => (int) $this->shop->getKey(),
            'topic' => 'orders/paid',
            'order_id' => $orderId,
            'webhook_event_id' => 1,
            'payload' => [
                'id' => $orderId,
                'total_price' => $total,
                'customer' => ['id' => self::CUSTOMER, 'email' => 'shopper@example.com'],
            ],
        ]]);
    }

    /** @param list<array<string, string>> $transactions */
    private function refund(string $refundId, string $orderId, array $transactions, ?Shop $shop = null): void
    {
        $event = WebhookEvent::create([
            'shop_id' => ($shop ?? $this->shop)->getKey(),
            'topic' => 'refunds/create',
            'raw_payload' => ['id' => $refundId, 'order_id' => $orderId, 'transactions' => $transactions],
        ]);

        app(RefundCreatedHandler::class)->handle($event);
    }
}
