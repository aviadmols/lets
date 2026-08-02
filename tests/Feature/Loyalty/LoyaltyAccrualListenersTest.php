<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Billing\Ledger;
use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Where money becomes points.
 *
 * Two rails feed the club and they must never both pay for one sale: our own
 * ledger covers every PayPlus-side success, and the Shopify order webhook covers
 * the sales that never reach it. The test that matters most is the one proving
 * an order we created ourselves is skipped by the second rail.
 */
final class LoyaltyAccrualListenersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'accrual.myshopify.com',
            'name' => 'Accrual',
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

    public function test_a_succeeded_ledger_row_accrues_points_for_a_member(): void
    {
        app(PointsEngine::class)->join('cust-1', 'dana@example.com');

        $this->succeedLedgerRow('gateway:1', 'cust-1', 250.0);

        $this->assertSame(250, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_a_pending_or_failed_row_accrues_nothing(): void
    {
        app(PointsEngine::class)->join('cust-1');

        $row = Ledger::open(
            shopId: (int) $this->shop->getKey(),
            chargeContext: PaymentLedger::CONTEXT_GATEWAY,
            idempotencyKey: 'gateway:fail',
            amount: 100.0,
            currency: 'ILS',
            attributes: ['shopify_customer_id' => 'cust-1'],
        );
        Ledger::transition($row, LedgerStatus::FAILED);

        $this->assertSame(0, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_the_same_ledger_row_never_pays_twice(): void
    {
        app(PointsEngine::class)->join('cust-1');

        $row = $this->succeedLedgerRow('gateway:2', 'cust-1', 80.0);
        // A replayed transition on the SAME row (idempotent by ledger id).
        Ledger::transition($row->refresh(), LedgerStatus::SUCCEEDED);

        $this->assertSame(80, (int) LoyaltyAccount::query()->first()->points_balance);
        $this->assertSame(1, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_EARN_PURCHASE)->count());
    }

    public function test_a_plain_shopify_order_accrues_through_the_webhook_event(): void
    {
        app(PointsEngine::class)->join('99', 'shopper@example.com');

        $this->fireShopifyOrderPaid('5001', '99', '340.00');

        $this->assertSame(340, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_the_same_shopify_order_never_pays_twice(): void
    {
        app(PointsEngine::class)->join('99');

        $this->fireShopifyOrderPaid('5001', '99', '100.00');
        $this->fireShopifyOrderPaid('5001', '99', '100.00'); // redelivered webhook

        $this->assertSame(100, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_an_order_we_created_for_a_payplus_charge_is_skipped(): void
    {
        // The cycle order our own ShopifyOrderCreator made: the ledger already
        // paid for this money, so the webhook rail must not pay again.
        app(PointsEngine::class)->join('99');

        $this->fireShopifyOrderPaid('7001', '99', '73.00', noteAttributes: [
            ['name' => 'pps_plan_public_id', 'value' => 'PLAN-ABC'],
        ]);

        $this->assertSame(0, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_an_order_already_in_our_ledger_is_skipped(): void
    {
        app(PointsEngine::class)->join('99');

        $this->succeedLedgerRow('gateway:8', '99', 50.0, orderId: '8001');
        $this->fireShopifyOrderPaid('8001', '99', '50.00');

        // 50 from the ledger, nothing from the webhook.
        $this->assertSame(50, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_a_guest_checkout_with_no_customer_is_ignored(): void
    {
        app(PointsEngine::class)->join('99');

        $this->fireShopifyOrderPaid('9001', '', '120.00');

        $this->assertSame(0, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    // === Helpers ===

    private function succeedLedgerRow(string $key, string $customerRef, float $amount, ?string $orderId = null): PaymentLedger
    {
        $row = Ledger::open(
            shopId: (int) $this->shop->getKey(),
            chargeContext: PaymentLedger::CONTEXT_GATEWAY,
            idempotencyKey: $key,
            amount: $amount,
            currency: 'ILS',
            attributes: array_filter([
                'shopify_customer_id' => $customerRef,
                'shopify_order_id' => $orderId,
            ]),
        );

        return Ledger::transition($row, LedgerStatus::SUCCEEDED);
    }

    /** @param list<array{name: string, value: string}> $noteAttributes */
    private function fireShopifyOrderPaid(string $orderId, string $customerId, string $total, array $noteAttributes = []): void
    {
        Event::dispatch('shopify.order.paid', [[
            'shop_id' => (int) $this->shop->getKey(),
            'topic' => 'orders/paid',
            'order_id' => $orderId,
            'webhook_event_id' => 1,
            'payload' => array_filter([
                'id' => $orderId,
                'total_price' => $total,
                'customer' => $customerId !== '' ? ['id' => $customerId, 'email' => 'shopper@example.com'] : null,
                'note_attributes' => $noteAttributes,
            ]),
        ]]);
    }
}
