<?php

namespace Tests\Feature;

use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * THE DUNNING POLICY, pinned.
 *
 * A cycle that does not go through is asked for once a day for ten days, on ONE
 * slot under ONE idempotency key. Then we stop asking for that cycle: it is
 * skipped — never collected retroactively — and the plan waits for its next
 * ordinary renewal in `awaiting_payment`, which is a LIVE subscription with an
 * unpaid cycle, not a dead one.
 */
final class DunningPolicyTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ATTEMPTS = 10;

    public int $callCount = 0;

    public bool $succeed = false;

    public function nextResult(): GatewayResult
    {
        $this->callCount++;

        if ($this->succeed) {
            return GatewayResult::fromResponse([
                'results' => ['status' => 'success', 'code' => 0],
                'data' => ['transaction' => ['uid' => 'txn-'.$this->callCount, 'approval_number' => 'A']],
            ]);
        }

        return GatewayResult::fromResponse([
            'results' => ['status' => 'error', 'code' => 'declined', 'description' => 'Card declined'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payplus.retry_daily_attempts', self::ATTEMPTS);
        Config::set('payplus.retry_interval_hours', 24);

        $this->callCount = 0;
        $this->succeed = false;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private DunningPolicyTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return $this->test->nextResult();
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

    // === The ladder ===

    public function test_the_first_failure_puts_the_subscription_into_awaiting_payment(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(PlanStatus::AWAITING_PAYMENT, $plan->fresh()->status);

        $slot = InstallmentPayment::where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(PaymentStatus::RETRY_SCHEDULED, $slot->status);
        $this->assertNotNull($slot->next_retry_at, 'The next attempt is scheduled…');
        $this->assertEqualsWithDelta(
            24,
            now()->diffInHours($slot->next_retry_at, absolute: true),
            1,
            '…for a day later, not hours later.',
        );
    }

    public function test_ten_daily_attempts_reuse_one_slot_and_one_ledger_row(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $cycle = $plan->next_charge_at;
        $orchestrator = app(ChargeOrchestrator::class);

        for ($day = 0; $day < self::ATTEMPTS; $day++) {
            $orchestrator->charge($plan->id, PaymentType::RECURRING);
            $this->travel(24)->hours();
        }

        $this->assertSame(self::ATTEMPTS, $this->callCount, 'Ten asks, one per day.');

        // ONE debt: one slot, one ledger row, one idempotency key throughout.
        $this->assertSame(1, InstallmentPayment::where('plan_id', $plan->id)->count());
        $this->assertSame(1, PaymentLedger::where('shop_id', $shop->id)->count());

        // And the scheduler asks no eleventh time. (A merchant pressing "charge
        // now" still can — that is a person deciding, not the ladder running.)
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        $this->assertSame(self::ATTEMPTS, $this->callCount, 'We stopped asking for this cycle.');

        $this->assertNotSame(
            $cycle->toDateString(),
            $plan->fresh()->next_charge_at->toDateString(),
            'The cycle was skipped, not retried forever.',
        );
    }

    public function test_the_skipped_cycle_lands_in_the_future_and_is_never_back_billed(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $orchestrator = app(ChargeOrchestrator::class);

        for ($day = 0; $day < self::ATTEMPTS; $day++) {
            $orchestrator->charge($plan->id, PaymentType::RECURRING);
            $this->travel(24)->hours();
        }

        $plan->refresh();

        // The whole point: the next date is AHEAD of us. A date left in the past
        // would be billed again on the very next scheduler tick — and every
        // missed cycle with it, minutes apart.
        $this->assertTrue(
            $plan->next_charge_at->isFuture(),
            'The next charge is scheduled forward, never into the past.',
        );
        $this->assertSame(PlanStatus::AWAITING_PAYMENT, $plan->status, 'Still a subscriber, still unpaid.');
    }

    public function test_a_plan_weeks_overdue_is_rolled_forward_whole_cycles_not_billed_for_each(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        // A worker outage: the plan comes back three months late.
        $plan->forceFill(['next_charge_at' => now()->subMonths(3)])->save();

        $orchestrator = app(ChargeOrchestrator::class);

        for ($day = 0; $day < self::ATTEMPTS; $day++) {
            $orchestrator->charge($plan->id, PaymentType::RECURRING);
            $this->travel(24)->hours();
        }

        $this->assertTrue($plan->fresh()->next_charge_at->isFuture());
        $this->assertSame(
            self::ATTEMPTS,
            $this->callCount,
            'Three missed months are three months nobody is charged for.',
        );
    }

    // === Recovery ===

    public function test_money_landing_mid_window_returns_the_subscription_to_active(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $cycle = $plan->next_charge_at;
        $orchestrator = app(ChargeOrchestrator::class);

        $orchestrator->charge($plan->id, PaymentType::RECURRING);
        $this->assertSame(PlanStatus::AWAITING_PAYMENT, $plan->fresh()->status);

        // The shopper fixes their card; the next daily attempt goes through.
        $this->travel(24)->hours();
        $this->succeed = true;
        $plan->refresh()->forceFill(['next_charge_at' => $cycle])->save();

        $orchestrator->charge($plan->id, PaymentType::RECURRING);

        $plan->refresh();
        $this->assertSame(PlanStatus::ACTIVE, $plan->status, 'The debt is paid; dunning is over.');
        $this->assertSame(1, InstallmentPayment::where('plan_id', $plan->id)->count(), 'One slot, recovered.');
        $this->assertSame(1, PaymentLedger::where('shop_id', $shop->id)->count(), 'One ledger row, recovered.');
    }

    // === What the rest of the system sees ===

    public function test_a_subscriber_in_dunning_still_counts_as_having_a_subscription(): void
    {
        $this->assertContains(
            PlanStatus::AWAITING_PAYMENT,
            PlanStatus::live(),
            'Otherwise the shop offers them a SECOND subscription while they owe on the first.',
        );

        $this->assertContains(
            PlanStatus::AWAITING_PAYMENT->value,
            PlanStatus::chargeable(),
            'Otherwise the next cycle is never attempted and the subscription silently dies.',
        );
    }

    public function test_the_scheduler_leaves_a_slot_alone_until_its_daily_retry_is_due(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);
        $this->callCount = 0;

        // The scheduler runs every five minutes. Between daily attempts it must
        // find nothing to do — otherwise the backoff is written and ignored.
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        $this->assertSame(0, $this->callCount, 'Not due yet — the day has not passed.');

        $this->travel(25)->hours();
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        $this->assertSame(1, $this->callCount, 'A day later, exactly one more ask.');
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function plan(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'dunning.myshopify.com',
            'name' => 'Dunning',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-dunning',
                'payplus_customer_uid' => 'cust-dunning',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-dunning',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-dunning',
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
