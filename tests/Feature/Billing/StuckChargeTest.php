<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\StuckChargeResolver;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A CHARGE WHOSE OUTCOME NOBODY EVER LEARNED.
 *
 * A `pending` ledger row means we asked PayPlus for money and the answer never
 * came back — a worker killed mid-charge, a redeploy, an OOM. What happens next
 * is the sharpest money decision in the app, because the two mistakes are not
 * equal: a missing charge is a button somebody presses once they have looked; a
 * DOUBLE charge is a customer's money taken twice, a refund, and a conversation.
 *
 * So the pipeline distinguishes by AGE and never guesses:
 *
 *   - younger than the in-flight window → a sibling attempt is at the gateway
 *     right now; this one stands down (the lock no longer spans the call);
 *   - older → nobody is coming back with the answer. We do NOT ask again. The
 *     row is flagged, the cycle stops, and a person who has looked at PayPlus
 *     says which way it went — the same doctrine `issued_documents` runs on.
 */
final class StuckChargeTest extends TestCase
{
    use RefreshDatabase;

    public int $gatewayCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gatewayCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private StuckChargeTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->gatewayCalls++;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-live', 'approval_number' => 'A']],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
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

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_charge_already_at_the_gateway_is_not_started_a_second_time(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $this->openPendingRow($shop, $plan, minutesAgo: 1);

        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(
            0,
            $this->gatewayCalls,
            'Two triggers reached PayPlus for one debt. The row lock no longer spans the gateway '
            .'call, so this guard is the only thing keeping them apart.',
        );
        $this->assertSame('charge_in_flight', $outcome->reason);
    }

    public function test_a_charge_we_lost_track_of_is_never_silently_re_sent(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $row = $this->openPendingRow($shop, $plan, minutesAgo: 120);

        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(
            0,
            $this->gatewayCalls,
            'The card may already have been charged for this exact debt. Asking again is how one '
            .'customer pays twice.',
        );
        $this->assertSame('needs_reconcile', $outcome->reason);

        $row->refresh();
        $this->assertTrue(
            (bool) ($row->raw_response_masked['needs_reconcile'] ?? false),
            'It must be findable by a person — this is not a state that resolves itself.',
        );
        $this->assertSame(LedgerStatus::PENDING->value, $row->status, 'pending IS the honest status.');
    }

    public function test_a_person_saying_the_money_moved_records_it(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $row = $this->openPendingRow($shop, $plan, minutesAgo: 120);
        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $resolved = app(StuckChargeResolver::class)
            ->resolve($row->refresh(), StuckChargeResolver::OUTCOME_TOOK, 'admin@example.com', 'txn-found');

        $this->assertTrue($resolved);

        $row->refresh();
        $this->assertSame(LedgerStatus::SUCCEEDED->value, $row->status);
        $this->assertSame('txn-found', $row->payplus_transaction_uid);
        $this->assertSame(49.90, round((float) $plan->fresh()->total_charged, 2), 'The plan is credited.');
    }

    public function test_a_person_saying_it_never_took_puts_the_debt_back_on_the_ladder(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $row = $this->openPendingRow($shop, $plan, minutesAgo: 120);
        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        app(StuckChargeResolver::class)
            ->resolve($row->refresh(), StuckChargeResolver::OUTCOME_DID_NOT, 'admin@example.com');

        $row->refresh();
        $this->assertSame(LedgerStatus::FAILED->value, $row->status);
        $this->assertSame(0.0, round((float) $plan->fresh()->total_charged, 2), 'Nothing moved, nothing credited.');

        // And the SAME slot is retried — not a second one for the same cycle.
        $this->assertSame(1, InstallmentPayment::where('plan_id', $plan->id)->count());

        $this->gatewayCalls = 0;
        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(1, $this->gatewayCalls, 'The ordinary ladder can ask again now.');
        $this->assertSame(1, InstallmentPayment::where('plan_id', $plan->id)->count(), 'Still one debt, one slot.');
    }

    public function test_the_same_stuck_row_cannot_be_resolved_twice(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $row = $this->openPendingRow($shop, $plan, minutesAgo: 120);
        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $resolver = app(StuckChargeResolver::class);

        $this->assertTrue($resolver->resolve($row->refresh(), StuckChargeResolver::OUTCOME_TOOK, 'a@example.com'));
        $this->assertFalse(
            $resolver->resolve($row->refresh(), StuckChargeResolver::OUTCOME_TOOK, 'b@example.com'),
            'Two admins on one row must not credit the plan twice for one charge.',
        );

        $this->assertSame(49.90, round((float) $plan->fresh()->total_charged, 2));
    }

    /** A committed `pending` row for this plan's current cycle, aged. */
    private function openPendingRow(Shop $shop, InstallmentPlan $plan, int $minutesAgo): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $plan, $minutesAgo): PaymentLedger {
            $payment = new InstallmentPayment;
            $payment->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'plan_id' => (int) $plan->getKey(),
                'sequence' => 1,
                'payment_type' => PaymentType::RECURRING->value,
                'amount' => 49.90,
                'currency' => 'ILS',
                'status' => PaymentStatus::PENDING->value,
            ])->save();

            $row = new PaymentLedger;
            $row->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'charge_context' => PaymentType::RECURRING->toChargeContext()->value,
                'idempotency_key' => sprintf(
                    'recurring:%d:%d:%s',
                    $shop->getKey(),
                    $plan->getKey(),
                    $plan->next_charge_at->format('Y-m-d'),
                ),
                'amount' => 49.90,
                'currency' => 'ILS',
                'status' => LedgerStatus::PENDING->value,
                'plan_id' => (int) $plan->getKey(),
                'payment_id' => (int) $payment->getKey(),
                'created_at' => now()->subMinutes($minutesAgo),
                'updated_at' => now()->subMinutes($minutesAgo),
            ])->save();

            return $row->fresh();
        });
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function plan(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'stuck.myshopify.com',
            'name' => 'Stuck',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-stuck',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-stuck',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-stuck',
                'installment_amount' => 49.90,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'next_charge_at' => now(),
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        });

        return [$shop, $plan];
    }
}
