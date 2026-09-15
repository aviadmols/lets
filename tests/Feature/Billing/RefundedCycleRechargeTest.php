<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Ledger;
use App\Domain\Lifecycle\RefundService;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A REFUNDED CYCLE CAN BE CHARGED AGAIN.
 *
 * A refund makes the cycle's ledger row and its payment slot FINAL — both state
 * machines have no way out of `refunded`. The engine then reused that slot for
 * the next charge (it only treated succeeded and failed slots as closed), so the
 * card was charged at PayPlus and the recording threw on refunded → succeeded:
 * money moved, nothing written, and the stuck-charge detector never saw it
 * because the row was not `pending`.
 *
 * Two shapes hit it. A merchant refunds a mistaken early charge and puts the
 * date back, so the SAME cycle is billed again on its real day (the 17
 * double-charged subscribers of 15/09). And a cycle refunded for any reason at
 * all, followed by the next month's charge — the ordinary case.
 */
final class RefundedCycleRechargeTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const OWED = '2026-10-14 00:00:00';

    public int $charges = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private RefundedCycleRechargeTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->charges;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n, 'approval_number' => 'A'.$n]],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'refund-of-'.$transactionUid]],
                ]);
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

    /** THE 15/09 REMEDIATION: refund the early charge, put the date back, bill on the real day. */
    public function test_a_refunded_cycle_put_back_on_the_calendar_is_charged_again_on_a_fresh_slot_and_key(): void
    {
        [$shop, $plan] = $this->plan();

        Tenant::run($shop, function () use ($plan): void {
            $this->travelTo(CarbonImmutable::parse(self::OWED)->addMinutes(3));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            $charged = PaymentLedger::query()->where('plan_id', $plan->id)->sole();
            $this->assertTrue(app(RefundService::class)->refund($charged)['ok']);

            // The merchant puts the cycle back where it belongs.
            $plan->fresh()->forceFill(['next_charge_at' => self::OWED])->save();

            // Past the one-charge-a-day wall, which is about money that moved today.
            $this->travelTo(CarbonImmutable::parse(self::OWED)->addDays(2));
            $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

            $this->assertTrue($outcome->isSucceeded(), 'the cycle is billed again');
            $this->assertSame(2, $this->charges);

            // The refunded records are untouched; the new charge has its own.
            $slots = InstallmentPayment::query()->where('plan_id', $plan->id)->orderBy('sequence')->get();
            $this->assertSame([PaymentStatus::REFUNDED, PaymentStatus::SUCCEEDED], $slots->pluck('status')->all());
            $this->assertSame([1, 2], $slots->pluck('sequence')->map(fn ($s) => (int) $s)->all());

            $rows = PaymentLedger::query()->where('plan_id', $plan->id)->orderBy('id')->get();
            $this->assertSame(LedgerStatus::REFUNDED->value, (string) $rows[0]->status);
            $this->assertSame(LedgerStatus::SUCCEEDED->value, (string) $rows[1]->status);
            $this->assertSame($rows[0]->idempotency_key.Ledger::RETAKE_SUFFIX.'2', $rows[1]->idempotency_key);

            // And the calendar moved ONE cycle on from the day that was owed.
            $this->assertSame('2026-11-14', $plan->fresh()->next_charge_at->format('Y-m-d'));
        });
    }

    /** THE ORDINARY CASE: a cycle refunded, then next month arrives. */
    public function test_a_refunded_cycle_does_not_swallow_the_next_cycle(): void
    {
        [$shop, $plan] = $this->plan();

        Tenant::run($shop, function () use ($plan): void {
            $this->travelTo(CarbonImmutable::parse(self::OWED)->addMinutes(3));
            app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);
            app(RefundService::class)->refund(PaymentLedger::query()->where('plan_id', $plan->id)->sole());

            // Next month, on its own date — the refund moved nothing on the calendar.
            $this->travelTo(CarbonImmutable::parse(self::OWED)->addMonth()->addMinutes(3));
            $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

            $this->assertTrue($outcome->isSucceeded());
            $this->assertSame(2, $this->charges);

            $slots = InstallmentPayment::query()->where('plan_id', $plan->id)->orderBy('sequence')->get();
            $this->assertSame([PaymentStatus::REFUNDED, PaymentStatus::SUCCEEDED], $slots->pluck('status')->all());

            // Its own cycle, its own key — no retake needed.
            $latest = PaymentLedger::query()->where('plan_id', $plan->id)->latest('id')->first();
            $this->assertStringEndsWith(':2026-11-14', $latest->idempotency_key);
        });
    }

    /** Each refund of one cycle spends one key; the refunded rows stay as history. */
    public function test_every_refund_of_the_same_cycle_spends_another_key(): void
    {
        [$shop, $plan] = $this->plan();

        Tenant::run($shop, function () use ($plan): void {
            $clock = CarbonImmutable::parse(self::OWED)->addMinutes(3);

            for ($round = 1; $round <= 3; $round++) {
                $this->travelTo($clock);
                $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded(), "round {$round}");

                if ($round < 3) {
                    $latest = PaymentLedger::query()->where('plan_id', $plan->id)->latest('id')->first();
                    app(RefundService::class)->refund($latest);
                    $plan->fresh()->forceFill(['next_charge_at' => self::OWED])->save();
                    $clock = $clock->addDays(2);
                }
            }

            $keys = PaymentLedger::query()->where('plan_id', $plan->id)->orderBy('id')->pluck('idempotency_key')->all();
            $this->assertCount(3, $keys);
            $this->assertStringEndsWith(':2026-10-14', $keys[0]);
            $this->assertStringEndsWith(Ledger::RETAKE_SUFFIX.'2', $keys[1]);
            $this->assertStringEndsWith(Ledger::RETAKE_SUFFIX.'3', $keys[2]);

            $this->assertSame(
                [LedgerStatus::REFUNDED->value, LedgerStatus::REFUNDED->value, LedgerStatus::SUCCEEDED->value],
                PaymentLedger::query()->where('plan_id', $plan->id)->orderBy('id')->pluck('status')->map(fn ($s) => (string) $s)->all(),
            );
        });
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: InstallmentPlan} an active plan with a card, owed on OWED */
    private function plan(): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'recharge.example.com',
            'name' => 'Recharge',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => 'cust-1',
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'pp-1',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'cust-1',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => Tenant::id(),
                'public_id' => 'PLN-'.uniqid(),
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'cust-1',
                'customer_name' => 'Dana',
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 59,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => self::OWED,
            ])->save();

            return $plan->fresh();
        })];
    }
}
