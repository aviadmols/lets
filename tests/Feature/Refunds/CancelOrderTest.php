<?php

namespace Tests\Feature\Refunds;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Cancel the order" — the mode where all three legs and the subscription move
 * together.
 *
 * The failure this exists to prevent is the quiet one: a merchant refunds an
 * order, believes the arrangement is over, and the scheduler bills the same
 * customer again next month. A cancellation that leaves the plan running is
 * worse than no cancellation at all, because it looks like it worked.
 */
final class CancelOrderTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearRefundFakes();
        parent::tearDown();
    }

    public function test_cancelling_stops_the_subscription_the_order_started(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $plan = $this->makePlan($shop, orderId: '7001');
        $charge = $this->makeCharge($shop, orderId: '7001', amount: 100.00, uid: 'txn-cycle');
        $this->linkChargeToPlan($shop, $charge, $plan);

        $request = $this->cancel($shop, orderId: '7001', planId: (int) $plan->getKey());

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
        $this->assertNull($plan->fresh()->next_charge_at, 'The clock is stopped, not merely the status.');
    }

    public function test_the_customer_is_not_emailed_a_second_time(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $plan = $this->makePlan($shop, orderId: '7002');
        $charge = $this->makeCharge($shop, orderId: '7002', amount: 100.00, uid: 'txn-cycle');
        $this->linkChargeToPlan($shop, $charge, $plan);

        $this->cancel($shop, orderId: '7002', planId: (int) $plan->getKey());

        // The merchant cancelled the order and is talking to the shopper
        // themselves; our own notice arriving minutes later reads as a fault.
        Mail::assertNothingSent();
    }

    public function test_the_credit_note_is_issued_under_the_cancellation_context(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '7003', amount: 100.00, uid: 'txn-sale');

        $this->cancel($shop, orderId: '7003');

        Queue::assertPushed(
            IssueDocumentJob::class,
            static fn (IssueDocumentJob $job): bool => $job->context === DocumentContext::CANCELLATION->value,
        );
    }

    public function test_the_store_is_asked_to_cancel_not_merely_to_refund(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '7004', amount: 100.00, uid: 'txn-sale');

        $this->cancel($shop, orderId: '7004');

        $this->assertSame(['cancel'], array_column($this->storeCalls, 'verb'));
    }

    /**
     * An order nobody ever paid for can still be cancelled — there is simply no
     * money leg. Refusing here would leave a merchant unable to close an
     * abandoned order from the one screen that knows about it.
     */
    public function test_an_unpaid_order_cancels_with_no_money_moving(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $plan = $this->makePlan($shop, orderId: '7005', status: PlanStatus::AWAITING_PAYMENT);

        $request = $this->cancel($shop, orderId: '7005', planId: (int) $plan->getKey());

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(RefundRequest::RAIL_NONE, $request->money_rail);
        $this->assertSame([], $this->gatewayRefunds);
        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
    }

    /**
     * A plan with money still in it is left alone by an ordinary refund: the
     * merchant credited one cycle, not the arrangement.
     */
    public function test_a_plain_refund_leaves_a_paid_plan_running(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $plan = $this->makePlan($shop, orderId: '7006', totalCharged: 300.00);
        $charge = $this->makeCharge($shop, orderId: '7006', amount: 100.00, uid: 'txn-cycle');
        $this->linkChargeToPlan($shop, $charge, $plan);

        $orchestrator = app(RefundOrchestrator::class);
        $orchestrator->run($shop, $orchestrator->open($shop, [
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'order_id' => '7006',
            'plan_id' => (int) $plan->getKey(),
        ]));

        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    /**
     * …but a plan with NOTHING paid into it is over, whatever the merchant
     * picked. Billing the remaining instalments of a purchase that no longer
     * exists is not a policy question.
     */
    public function test_a_refund_that_empties_a_plan_ends_it(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        $plan = $this->makePlan($shop, orderId: '7007', totalCharged: 0.0);
        $charge = $this->makeCharge($shop, orderId: '7007', amount: 100.00, uid: 'txn-deposit');
        $this->linkChargeToPlan($shop, $charge, $plan);

        $orchestrator = app(RefundOrchestrator::class);
        $orchestrator->run($shop, $orchestrator->open($shop, [
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'order_id' => '7007',
            'plan_id' => (int) $plan->getKey(),
        ]));

        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
    }

    public function test_every_plan_born_from_the_order_is_stopped_not_only_the_named_one(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();

        // A cart with two subscription lines: one order, two plans.
        $first = $this->makePlan($shop, orderId: '7008');
        $second = $this->makePlan($shop, orderId: '7008');
        $charge = $this->makeCharge($shop, orderId: '7008', amount: 100.00, uid: 'txn-sale');
        $this->linkChargeToPlan($shop, $charge, $first);

        $this->cancel($shop, orderId: '7008', planId: (int) $first->getKey());

        $this->assertSame(PlanStatus::CANCELLED, $first->fresh()->status);
        $this->assertSame(
            PlanStatus::CANCELLED,
            $second->fresh()->status,
            'Cancelling the order while its sibling keeps billing is the failure this leg exists to prevent.',
        );
    }

    public function test_a_stuck_store_leg_does_not_stop_the_subscription_early(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore(failWith: 'order_locked');

        $plan = $this->makePlan($shop, orderId: '7009');
        $charge = $this->makeCharge($shop, orderId: '7009', amount: 100.00, uid: 'txn-sale');
        $this->linkChargeToPlan($shop, $charge, $plan);

        $request = $this->cancel($shop, orderId: '7009', planId: (int) $plan->getKey());

        $this->assertSame(RefundRequest::STATUS_NEEDS_ATTENTION, $request->status);
        $this->assertSame(
            PlanStatus::ACTIVE,
            $plan->fresh()->status,
            'The store has not caught up; the plan leg waits with it.',
        );

        // The retry finishes the job the first run could not.
        $this->fakeStore();
        app(RefundOrchestrator::class)->retryStore($shop, $request);

        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
    }

    // === Helpers ===

    private function cancel(Shop $shop, string $orderId, ?int $planId = null): RefundRequest
    {
        $orchestrator = app(RefundOrchestrator::class);

        return $orchestrator->run($shop, $orchestrator->open($shop, array_filter([
            'mode' => RefundRequest::MODE_CANCEL_ORDER,
            'order_id' => $orderId,
            'plan_id' => $planId,
            'restock' => true,
        ], static fn ($v): bool => $v !== null)));
    }

    private function makePlan(
        Shop $shop,
        string $orderId,
        PlanStatus $status = PlanStatus::ACTIVE,
        float $totalCharged = 100.00,
    ): InstallmentPlan {
        return Tenant::run($shop, function () use ($shop, $orderId, $status, $totalCharged): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'public_id' => 'PLN-'.uniqid('', true),
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => $status->value,
                'external_order_id' => $orderId,
                'customer_name' => 'Dana Subscriber',
                'customer_email' => 'dana@example.com',
                'total_amount' => 0,
                'total_charged' => $totalCharged,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'next_charge_at' => now()->addMonth(),
            ])->save();

            return $plan->fresh();
        });
    }

    private function linkChargeToPlan(Shop $shop, PaymentLedger $charge, InstallmentPlan $plan): void
    {
        Tenant::run($shop, static fn () => $charge->forceFill(['plan_id' => $plan->getKey()])->save());
    }
}
