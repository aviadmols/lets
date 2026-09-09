<?php

namespace Tests\Feature\Refunds;

use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Domain\Refunds\StoreRefunderFactory;
use App\Domain\Refunds\WooStoreRefunder;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Telling WooCommerce that money went back.
 *
 * The flag this file exists for is `api_refund`. It decides WHOSE money moves:
 * false records a refund that already happened, true asks the order's own gateway
 * to send money now. Every order this app charged was paid on the PayPlus page,
 * PayPlus has already returned the money by the time the store leg runs, and a
 * `true` there would refund the shopper a second time with nothing in either
 * system saying so.
 */
final class WooStoreRefunderTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    /**
     * What `GET /orders/{id}` answers with. Mutable, and read through a closure,
     * because a second Http::fake() does NOT replace the first: Laravel keeps
     * every registered stub and the earliest match wins, so a catch-all
     * registered first would swallow the re-registered order route.
     *
     * @var array<int, array<string, string>>
     */
    public array $wooOrderMeta = [];

    protected function tearDown(): void
    {
        $this->clearRefundFakes();
        parent::tearDown();
    }

    public function test_a_payplus_charged_order_is_recorded_not_re_refunded(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest();

        $this->makeCharge($shop, orderId: '5001', amount: 100.00, uid: 'txn-sale');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '5001']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);

        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), '/orders/5001/refunds')) {
                return false;
            }

            return $r['api_refund'] === false
                && $r['amount'] === '100.00';
        });
    }

    public function test_restock_follows_the_merchants_choice(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest();

        $this->makeCharge($shop, orderId: '5002', amount: 100.00, uid: 'txn-sale');

        $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'order_id' => '5002',
            'restock' => true,
        ]);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/orders/5002/refunds')
            && $r['restock_items'] === true);
    }

    public function test_the_marker_is_written_on_the_order_and_read_back(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest();

        $this->makeCharge($shop, orderId: '5003', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '5003']);

        $marker = WooStoreRefunder::MARKER_PREFIX.$request->getKey();

        Http::assertSent(function (Request $r) use ($marker): bool {
            if ($r->method() !== 'PUT' || ! str_contains($r->url(), '/orders/5003')) {
                return false;
            }

            return collect((array) ($r['meta_data'] ?? []))
                ->contains(static fn (array $m): bool => ($m['key'] ?? '') === $marker);
        });

        // …and the same marker coming back means "already done", so a retry stops.
        $this->wooOrderMeta = [['key' => $marker, 'value' => 'x']];

        $this->assertTrue(app(WooStoreRefunder::class)->alreadyApplied($shop, $request));
    }

    public function test_a_cancellation_records_the_refund_before_it_cancels(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest();

        $this->makeCharge($shop, orderId: '5004', amount: 100.00, uid: 'txn-sale');

        $this->refund($shop, [
            'mode' => RefundRequest::MODE_CANCEL_ORDER,
            'order_id' => '5004',
            'restock' => true,
        ]);

        $order = [];
        Http::assertSent(function (Request $r) use (&$order): bool {
            if (str_contains($r->url(), '/orders/5004/refunds')) {
                $order[] = 'refund';
            } elseif ($r->method() === 'PUT' && str_contains($r->url(), '/orders/5004') && isset($r['status'])) {
                $order[] = 'cancel';
            }

            return true;
        });

        $this->assertSame(
            ['refund', 'cancel'],
            array_values(array_filter($order, static fn (string $s): bool => in_array($s, ['refund', 'cancel'], true))),
            'WooCommerce restocks on the transition to cancelled, so the refund (which may restock too) goes first.',
        );
    }

    public function test_a_store_that_refuses_leaves_the_refund_needing_attention(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();

        Http::fake([
            '*/wp-json/wc/v3/orders/5005/refunds' => Http::response(['message' => 'nope'], 500),
            '*' => Http::response([], 200),
        ]);

        $this->makeCharge($shop, orderId: '5005', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '5005']);

        $this->assertSame(RefundRequest::STATUS_NEEDS_ATTENTION, $request->status);
        $this->assertSame(100.00, $request->refundedTotal(), 'The shopper still has their money.');
    }

    public function test_a_charge_with_no_store_order_skips_the_store_without_failing(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest();

        // An account-area purchase: a charge that never had an order of its own.
        $charge = $this->makeUpsellCharge($shop, parentOrderId: '', amount: 30.00, uid: 'txn-standalone');

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'ledger_id' => (int) $charge->getKey(),
        ]);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame('no_order', $request->store_result['details']['reason']);
    }

    // === Helpers ===

    private function fakeWooRest(): void
    {
        $test = $this;

        Http::fake([
            '*/wp-json/wc/v3/orders/*/refunds' => Http::response(['id' => 91001], 201),
            '*/wp-json/wc/v3/orders/*/notes' => Http::response(['id' => 5], 201),
            // No return type: Http::response() hands back a PROMISE, not a
            // Response, and typing it as one turns every stubbed call into a
            // TypeError the refunder then reports as a store failure.
            '*/wp-json/wc/v3/orders/*' => static fn () => Http::response(
                ['id' => 5001, 'meta_data' => $test->wooOrderMeta],
                200,
            ),
            '*' => Http::response([], 200),
        ]);
    }

    private function wooShop(): Shop
    {
        // The real refunder, not the recording fake — this file is about the
        // payload WooCommerce actually receives.
        StoreRefunderFactory::clearFake();

        $shop = $this->makeShop(Shop::PLATFORM_WOOCOMMERCE);

        $shop->woocommerce_credentials = [
            'base_url' => 'https://refunds.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    /** @param array<string, mixed> $input */
    private function refund(Shop $shop, array $input): RefundRequest
    {
        $orchestrator = app(RefundOrchestrator::class);

        return $orchestrator->run($shop, $orchestrator->open($shop, $input));
    }
}
