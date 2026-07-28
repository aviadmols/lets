<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Lifecycle\OrderRefundService;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Refunding an ORDER, not a row.
 *
 * One order is often two charges: the checkout, plus an accepted post-purchase
 * upsell charged separately on the saved card. Refunding from a payment row only
 * ever reversed the row that was clicked — the shopper stayed out of pocket for
 * the rest, and the paperwork stopped describing reality.
 *
 * Two charges must also produce TWO credit notes: a credit note credits ONE sale
 * document, and these were two separate sales.
 */
final class OrderRefundTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{uid: string, amount: float}> */
    public array $refunds = [];

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_refunds_the_checkout_and_the_upsell_of_one_order(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        $this->gatewayCharge($shop, orderId: '2820', amount: 1.00, uid: 'txn-sale');
        $this->upsellCharge($shop, parentOrderId: '2820', amount: 0.50, uid: 'txn-upsell');

        $result = app(OrderRefundService::class)->refundOrder($shop, '2820');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['refunded'], 'The upsell is part of the order the shopper paid for.');
        $this->assertSame(1.50, $result['total']);

        // Both went to PayPlus, each by its OWN transaction uid.
        $this->assertEqualsCanonicalizing(['txn-sale', 'txn-upsell'], array_column($this->refunds, 'uid'));

        Tenant::run($shop, function (): void {
            $this->assertSame(2, PaymentLedger::query()->where('status', 'refunded')->count());
        });
    }

    public function test_each_refunded_charge_gets_its_own_credit_note(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        $this->gatewayCharge($shop, orderId: '2820', amount: 1.00, uid: 'txn-sale');
        $this->upsellCharge($shop, parentOrderId: '2820', amount: 0.50, uid: 'txn-upsell');

        app(OrderRefundService::class)->refundOrder($shop, '2820');

        // TWO credit documents, not one: a credit note credits ONE sale document.
        Queue::assertPushed(IssueDocumentJob::class, 2);
    }

    public function test_a_declined_charge_does_not_roll_back_the_ones_that_reversed(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway(failFor: 'txn-upsell');

        $this->gatewayCharge($shop, orderId: '2820', amount: 1.00, uid: 'txn-sale');
        $this->upsellCharge($shop, parentOrderId: '2820', amount: 0.50, uid: 'txn-upsell');

        $result = app(OrderRefundService::class)->refundOrder($shop, '2820');

        // Reported as a partial, never as success…
        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['refunded']);
        $this->assertSame(1, $result['failed']);

        // …and the money that already left is NOT undone.
        Tenant::run($shop, function (): void {
            $this->assertSame(1, PaymentLedger::query()->where('status', 'refunded')->count());
            $this->assertSame(1, PaymentLedger::query()->where('status', 'succeeded')->count());
        });
    }

    public function test_an_order_with_nothing_to_refund_says_so(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        $result = app(OrderRefundService::class)->refundOrder($shop, '9999');

        $this->assertFalse($result['ok']);
        $this->assertSame(['nothing_to_refund'], $result['messages']);
        $this->assertSame([], $this->refunds, 'Nothing may reach the gateway.');
    }

    public function test_another_orders_charges_are_never_touched(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        $this->gatewayCharge($shop, orderId: '2820', amount: 1.00, uid: 'txn-a');
        $this->gatewayCharge($shop, orderId: '2821', amount: 5.00, uid: 'txn-b');

        $result = app(OrderRefundService::class)->refundOrder($shop, '2820');

        $this->assertSame(1, $result['refunded']);
        $this->assertSame(['txn-a'], array_column($this->refunds, 'uid'));
    }

    // === Fixtures ===

    private function shop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'order-refund.example.com',
            'name' => 'Order Refund',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk',
            'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function gatewayCharge(Shop $shop, string $orderId, float $amount, string $uid): PaymentLedger
    {
        return $this->charge($shop, PaymentLedger::CONTEXT_GATEWAY, IdempotencyKey::gateway((int) $shop->getKey(), $orderId), $amount, $uid, [
            'shopify_order_id' => $orderId,
        ]);
    }

    private function upsellCharge(Shop $shop, string $parentOrderId, float $amount, string $uid): PaymentLedger
    {
        // An upsell records the purchase it FOLLOWED, and has no order of its own.
        return $this->charge($shop, PaymentLedger::CONTEXT_UPSELL, 'upsell:'.$shop->getKey().':'.$parentOrderId, $amount, $uid, [
            'parent_order_id' => $parentOrderId,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function charge(Shop $shop, string $context, string $key, float $amount, string $uid, array $attributes): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $context, $key, $amount, $uid, $attributes): PaymentLedger {
            $row = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: $context,
                idempotencyKey: $key,
                amount: $amount,
                currency: 'ILS',
                attributes: array_merge(['payplus_transaction_uid' => $uid], $attributes),
            );

            return Ledger::transition($row, LedgerStatus::SUCCEEDED);
        });
    }

    private function fakeGateway(?string $failFor = null): void
    {
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test, $failFor) implements PayPlusGatewayInterface
        {
            public function __construct(private OrderRefundTest $test, private ?string $failFor) {}

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                if ($this->failFor === $transactionUid) {
                    return GatewayResult::fromResponse(['results' => ['status' => 'error', 'description' => 'declined']]);
                }

                $this->test->refunds[] = ['uid' => $transactionUid, 'amount' => $amount];

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success'],
                    'data' => ['transaction' => ['uid' => 'refund-'.$transactionUid]],
                ]);
            }

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }
}
