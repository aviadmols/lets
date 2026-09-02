<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
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
 * WHAT HAPPENS TO THE CYCLE AFTER THE ONE WE GAVE UP ON.
 *
 * DunningPolicyTest pins the window itself: ten daily asks on ONE slot under ONE
 * key, then we stop asking for THAT cycle and point the plan at its next
 * ordinary renewal. This file pins what the NEXT renewal is owed — which the
 * ladder above is silent about, and which is where a subscription quietly dies.
 *
 * A recurring slot's sequence is derived from the count of SUCCEEDED payments,
 * so a cycle nobody paid for does not advance it: next month lands on the very
 * same slot row, carrying last month's exhausted attempt counter and last
 * month's frozen price. Two consequences, one root:
 *
 *   - the new cycle is born with its dunning window already spent, so it gets a
 *     single ask instead of the ten the merchant configured — for the rest of
 *     the subscription's life;
 *   - the new cycle is charged at the price stamped on the old slot, so a price
 *     the merchant has since changed is never collected.
 *
 * A cycle is a debt of its own. It gets its own slot, its own window, and its
 * own price.
 */
final class CycleAfterGivingUpTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ATTEMPTS = 10;

    private const OLD_PRICE = 49.90;

    private const NEW_PRICE = 79.90;

    public int $callCount = 0;

    public bool $succeed = false;

    /** Every amount the gateway was asked for, in order. */
    public array $amountsCharged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payplus.retry_daily_attempts', self::ATTEMPTS);
        Config::set('payplus.retry_interval_hours', 24);

        $this->callCount = 0;
        $this->succeed = false;
        $this->amountsCharged = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private CycleAfterGivingUpTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->callCount++;
                $this->test->amountsCharged[] = round($amount, 2);

                return $this->test->succeed
                    ? GatewayResult::fromResponse([
                        'results' => ['status' => 'success', 'code' => 0],
                        'data' => ['transaction' => ['uid' => 'txn-'.$this->test->callCount, 'approval_number' => 'A']],
                    ])
                    : GatewayResult::fromResponse([
                        'results' => ['status' => 'error', 'code' => 'declined', 'description' => 'Card declined'],
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

    public function test_the_next_cycle_gets_a_fresh_dunning_window(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $this->exhaustOneCycle($plan);

        // Next month arrives. The scheduler would dispatch on next_charge_at.
        $this->travelTo($plan->fresh()->next_charge_at);
        $this->callCount = 0;

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $slot = InstallmentPayment::where('plan_id', $plan->id)
            ->orderByDesc('sequence')
            ->firstOrFail();

        $this->assertSame(
            PaymentStatus::RETRY_SCHEDULED,
            $slot->status,
            'A brand-new cycle was given up on after ONE ask: it inherited the previous '
            .'cycle\'s spent attempt counter, so the ten-day window never opens again.',
        );
        $this->assertNotNull($slot->next_retry_at, 'Tomorrow\'s attempt is scheduled.');
        $this->assertSame(1, (int) $slot->attempt_count, 'The new cycle has been asked for exactly once.');
    }

    public function test_the_next_cycle_is_charged_at_the_price_it_is_worth_today(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $this->exhaustOneCycle($plan);

        // The merchant raises the subscription price between cycles.
        $plan->fresh()->forceFill(['installment_amount' => self::NEW_PRICE])->save();

        $this->travelTo($plan->fresh()->next_charge_at);
        $this->amountsCharged = [];
        $this->succeed = true;

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(
            [self::NEW_PRICE],
            $this->amountsCharged,
            'The new cycle was charged the OLD price: the amount is stamped once when the '
            .'slot is created, and this cycle reused the slot last month left behind.',
        );
    }

    public function test_a_cycle_nobody_paid_for_does_not_become_the_next_cycles_slot(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $this->exhaustOneCycle($plan);

        $this->travelTo($plan->fresh()->next_charge_at);
        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(
            2,
            InstallmentPayment::where('plan_id', $plan->id)->count(),
            'Two cycles were billed, so there are two debts and two slots — the second '
            .'must not be written over the first.',
        );
    }

    /** Run one cycle's ten daily asks into the ground. */
    private function exhaustOneCycle(InstallmentPlan $plan): void
    {
        $orchestrator = app(ChargeOrchestrator::class);

        for ($day = 0; $day < self::ATTEMPTS; $day++) {
            $orchestrator->charge($plan->id, PaymentType::RECURRING);
            $this->travel(24)->hours();
        }

        $this->assertSame(self::ATTEMPTS, $this->callCount, 'Precondition: the window ran out.');
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function plan(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'nextcycle.myshopify.com',
            'name' => 'Next cycle',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-nextcycle',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-nextcycle',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-nextcycle',
                'installment_amount' => self::OLD_PRICE,
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
