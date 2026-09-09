<?php

namespace Tests\Feature\Refunds;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * One click, three legs — and what happens when one of them breaks.
 *
 * The money leaving is the only irreversible part, so everything here is built
 * around one rule: a refund that has already moved money must never be reported
 * as "failed", never be retried into a second refund, and never be quietly
 * marked done while the merchant's own store still says the order was paid.
 */
final class RefundOrchestratorTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearRefundFakes();
        parent::tearDown();
    }

    // === The money leg ===

    public function test_a_full_refund_reverses_every_charge_on_the_order(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3001', amount: 100.00, uid: 'txn-sale');
        $this->makeUpsellCharge($shop, parentOrderId: '3001', amount: 40.00, uid: 'txn-upsell');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3001']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(140.00, $request->refundedTotal(), 'The upsell is part of what the shopper paid.');
        $this->assertEqualsCanonicalizing(
            ['txn-sale', 'txn-upsell'],
            array_column($this->gatewayRefunds, 'uid'),
        );
    }

    public function test_a_partial_refund_comes_off_the_newest_charge_first(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3002', amount: 100.00, uid: 'txn-old');
        $this->makeUpsellCharge($shop, parentOrderId: '3002', amount: 40.00, uid: 'txn-new');

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'order_id' => '3002',
            'amount' => 25.00,
        ]);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(
            [['uid' => 'txn-new', 'amount' => 25.00]],
            array_map(
                static fn (array $r): array => ['uid' => $r['uid'], 'amount' => $r['amount']],
                $this->gatewayRefunds,
            ),
            'The cycle they are unhappy about is the one that just billed.',
        );
    }

    public function test_a_partial_refund_spills_onto_the_next_charge_when_the_newest_cannot_cover_it(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3003', amount: 100.00, uid: 'txn-old');
        $this->makeUpsellCharge($shop, parentOrderId: '3003', amount: 40.00, uid: 'txn-new');

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'order_id' => '3003',
            'amount' => 60.00,
        ]);

        $this->assertSame(60.00, $request->refundedTotal());
        $this->assertSame(
            [['uid' => 'txn-new', 'amount' => 40.00], ['uid' => 'txn-old', 'amount' => 20.00]],
            array_map(
                static fn (array $r): array => ['uid' => $r['uid'], 'amount' => $r['amount']],
                $this->gatewayRefunds,
            ),
        );
    }

    public function test_asking_for_more_than_remains_is_refused_before_any_money_moves(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3004', amount: 100.00, uid: 'txn-sale');

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'order_id' => '3004',
            'amount' => 150.00,
        ]);

        $this->assertSame(RefundRequest::STATUS_FAILED, $request->status);
        $this->assertSame(RefundRequest::FAIL_NOTHING_TO_REFUND, $request->failure_code);
        $this->assertSame([], $this->gatewayRefunds, 'Nothing may reach the gateway on a refusal.');
        $this->assertSame([], $this->storeCalls, 'And the store is never told about money that did not move.');
    }

    public function test_a_request_scoped_to_one_charge_never_touches_its_neighbour(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $sale = $this->makeCharge($shop, orderId: '3005', amount: 100.00, uid: 'txn-sale');
        $this->makeUpsellCharge($shop, parentOrderId: '3005', amount: 40.00, uid: 'txn-upsell');

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'order_id' => '3005',
            'ledger_id' => (int) $sale->getKey(),
        ]);

        $this->assertSame(100.00, $request->refundedTotal());
        $this->assertSame(['txn-sale'], array_column($this->gatewayRefunds, 'uid'));
    }

    public function test_a_declined_charge_leaves_the_one_that_reversed_alone_and_says_so(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway(declineUids: ['txn-upsell']);
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3006', amount: 100.00, uid: 'txn-sale');
        $this->makeUpsellCharge($shop, parentOrderId: '3006', amount: 40.00, uid: 'txn-upsell');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3006']);

        // The run continues — that ₪100 is already travelling.
        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(100.00, $request->refundedTotal());
        $this->assertSame(
            RefundRequest::FAIL_MONEY,
            $request->failure_code,
            'A partial success is not a green tick.',
        );

        Tenant::run($shop, function (): void {
            $this->assertSame(1, PaymentLedger::query()->where('status', 'refunded')->count());
            $this->assertSame(1, PaymentLedger::query()->where('status', 'succeeded')->count());
        });
    }

    public function test_nothing_refundable_fails_without_calling_anyone(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '9999']);

        $this->assertSame(RefundRequest::STATUS_FAILED, $request->status);
        $this->assertSame(RefundRequest::FAIL_NOTHING_TO_REFUND, $request->failure_code);
        $this->assertSame([], $this->gatewayRefunds);
    }

    // === Idempotency ===

    public function test_a_double_clicked_drawer_opens_one_request(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3007', amount: 100.00, uid: 'txn-sale');

        $input = ['mode' => RefundRequest::MODE_REFUND_PARTIAL, 'order_id' => '3007', 'amount' => 50.00];

        $first = app(RefundOrchestrator::class)->open($shop, $input);
        $second = app(RefundOrchestrator::class)->open($shop, $input);

        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        Tenant::run($shop, fn () => $this->assertSame(1, RefundRequest::query()->count()));
    }

    public function test_a_genuine_second_slice_of_the_same_size_is_its_own_request(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3008', amount: 100.00, uid: 'txn-sale');

        $input = ['mode' => RefundRequest::MODE_REFUND_PARTIAL, 'order_id' => '3008', 'amount' => 50.00];

        $first = $this->refund($shop, $input);
        $second = $this->refund($shop, $input);

        $this->assertNotSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame(RefundRequest::STATUS_COMPLETED, $second->status);
        $this->assertSame(100.00, array_sum(array_column($this->gatewayRefunds, 'amount')));
    }

    public function test_running_a_settled_request_again_moves_no_more_money(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3009', amount: 100.00, uid: 'txn-sale');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3009']);
        app(RefundOrchestrator::class)->run($shop, $request);

        $this->assertCount(1, $this->gatewayRefunds);
    }

    // === The store leg ===

    public function test_a_store_failure_after_the_money_moved_becomes_a_task_not_an_error(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore(failWith: 'order_locked');

        $this->makeCharge($shop, orderId: '3010', amount: 100.00, uid: 'txn-sale');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3010']);

        $this->assertSame(RefundRequest::STATUS_NEEDS_ATTENTION, $request->status);
        $this->assertSame(RefundRequest::FAIL_STORE, $request->failure_code);
        $this->assertSame(100.00, $request->refundedTotal(), 'The shopper still has their money.');
        $this->assertSame('order_locked', $request->store_result['error']);
    }

    public function test_retrying_the_store_leg_clears_the_task_and_never_refunds_again(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore(failWith: 'order_locked');

        $this->makeCharge($shop, orderId: '3011', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3011']);

        // The merchant fixes whatever was wrong and presses the button.
        $this->fakeStore();
        $request = app(RefundOrchestrator::class)->retryStore($shop, $request);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertCount(1, $this->gatewayRefunds, 'A store retry is not a second refund.');
    }

    public function test_a_store_that_already_carries_this_request_is_not_written_to_twice(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore(applied: true);

        $this->makeCharge($shop, orderId: '3012', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3012']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertTrue($request->store_result['skipped']);
        $this->assertSame([], $this->storeCalls);
    }

    public function test_a_shop_with_no_store_connection_still_completes_the_refund(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        // No fakeStore(): the factory finds nobody to tell.

        $this->makeCharge($shop, orderId: '3013', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3013']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame('no_store_connection', $request->store_result['details']['reason']);
    }

    // === The paperwork leg ===

    public function test_each_reversed_charge_queues_its_own_credit_note(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3014', amount: 100.00, uid: 'txn-sale');
        $this->makeUpsellCharge($shop, parentOrderId: '3014', amount: 40.00, uid: 'txn-upsell');

        $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3014']);

        // A credit note credits ONE sale document, and these were two sales.
        Queue::assertPushed(IssueDocumentJob::class, 2);
        Queue::assertPushed(
            IssueDocumentJob::class,
            static fn (IssueDocumentJob $job): bool => $job->context === DocumentContext::REFUND->value
                && $job->refundRequestId !== null,
        );
    }

    /**
     * Two equal slices are TWO credit notes. On the amount alone the second one
     * resolved to the first one's document: the customer got ₪100 back and the
     * books declared ₪50.
     */
    public function test_two_refunds_of_the_same_amount_are_two_different_documents(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3015', amount: 200.00, uid: 'txn-sale');

        $input = ['mode' => RefundRequest::MODE_REFUND_PARTIAL, 'order_id' => '3015', 'amount' => 50.00];
        $this->refund($shop, $input);
        $this->refund($shop, $input);

        $keys = [];
        Queue::assertPushed(IssueDocumentJob::class, function (IssueDocumentJob $job) use (&$keys): bool {
            $keys[] = $job->uniqueId();

            return true;
        });

        $this->assertCount(2, array_unique($keys), 'Two credit notes, not one swallowed by the other.');
    }

    // === The machine ===

    public function test_the_request_refuses_a_move_the_machine_does_not_allow(): void
    {
        Queue::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '3016', amount: 100.00, uid: 'txn-sale');
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '3016']);

        $this->assertFalse(
            $request->moveTo(RefundRequest::STATUS_PENDING),
            'A completed refund cannot be reopened — the money has gone.',
        );
        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->fresh()->status);
    }

    // === Helper ===

    /** @param array<string, mixed> $input */
    private function refund(Shop $shop, array $input): RefundRequest
    {
        $orchestrator = app(RefundOrchestrator::class);

        return $orchestrator->run($shop, $orchestrator->open($shop, $input));
    }
}
