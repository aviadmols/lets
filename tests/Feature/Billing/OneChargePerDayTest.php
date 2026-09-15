<?php

namespace Tests\Feature\Billing;

use App\Domain\Lifecycle\ChargeNowService;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ONE CHARGE PER SUBSCRIPTION PER DAY, unless a person explicitly approves another.
 *
 * Each test here is a double charge that really happened in production. The cycle
 * key is built from the next charge date, a success moves that date a month on,
 * and so a second request straight after a success wore a NEW key and charged next
 * month — 17 subscribers, twice each, between 10/09 and 15/09.
 */
final class OneChargePerDayTest extends TestCase
{
    use RefreshDatabase;

    public int $gatewayCalls = 0;

    /** When true, the next gateway call declines. */
    public bool $declineNext = false;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private OneChargePerDayTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->gatewayCalls;

                if ($this->test->declineNext) {
                    $this->test->declineNext = false;

                    return GatewayResult::fromResponse([
                        'results' => ['status' => 'error', 'code' => 1, 'description' => 'כרטיס חסום'],
                    ]);
                }

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n, 'approval_number' => 'A'.$n]],
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

    /**
     * 14/09, 18:05 → 18:08: the scheduler queued a second job for a plan whose
     * first charge had not run yet. The first charged September; the second ran
     * three minutes later and charged October.
     */
    public function test_a_second_request_straight_after_a_success_does_not_charge_next_month(): void
    {
        [$shop, $plan] = $this->duePlan();
        $due = $plan->next_charge_at->copy();

        $first = Tenant::run($shop, fn () => app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING));
        $second = Tenant::run($shop, fn () => app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING));

        $this->assertTrue($first->isSucceeded());
        $this->assertSame(ChargeOutcome::RESULT_SKIPPED, $second->result);
        $this->assertSame(ChargeOrchestrator::SKIP_CHARGED_RECENTLY, $second->reason);
        $this->assertSame(1, $this->gatewayCalls, 'PayPlus is asked once');

        Tenant::run($shop, function () use ($plan, $due): void {
            // Advanced by ONE cycle — not two.
            $this->assertTrue($plan->fresh()->next_charge_at->isSameDay($due->copy()->addMonth()));
            $this->assertSame(1, InstallmentPayment::query()->whereNotNull('charged_at')->count());
            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CHARGE_REPEAT_BLOCKED,
            ]);
        });
    }

    /** 10/09 09:07: "charge now" pressed twice, eight seconds apart. */
    public function test_pressing_charge_now_twice_charges_once(): void
    {
        [$shop, $plan] = $this->duePlan();

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeNowService::class)->chargeNow($plan->fresh());
            $second = app(ChargeNowService::class)->chargeNow($plan->fresh());

            $this->assertSame(ChargeOrchestrator::SKIP_CHARGED_RECENTLY, $second->reason);
        });

        $this->assertSame(1, $this->gatewayCalls);
    }

    /** The one way through: a person said so, and the plan records that they did. */
    public function test_an_explicitly_approved_second_charge_goes_through_and_is_recorded(): void
    {
        [$shop, $plan] = $this->duePlan();

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeNowService::class)->chargeNow($plan->fresh());
            $approved = app(ChargeNowService::class)->chargeNow($plan->fresh(), repeatApproved: true);

            $this->assertTrue($approved->isSucceeded());
            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CHARGE_REPEAT_APPROVED,
            ]);
        });

        $this->assertSame(2, $this->gatewayCalls);
    }

    /** A decline moved no money — asking again is how a card gets fixed. */
    public function test_a_declined_attempt_today_does_not_block_another_try(): void
    {
        [$shop, $plan] = $this->duePlan();
        $this->declineNext = true;

        Tenant::run($shop, function () use ($plan): void {
            $first = app(ChargeNowService::class)->chargeNow($plan->fresh());
            $second = app(ChargeNowService::class)->chargeNow($plan->fresh());

            $this->assertSame(ChargeOutcome::RESULT_FAILED, $first->result);
            $this->assertTrue($second->isSucceeded());
        });

        $this->assertSame(2, $this->gatewayCalls);
    }

    /** A rolling day: yesterday's charge does not stand in the way of today's cycle. */
    public function test_a_charge_more_than_a_day_ago_does_not_block(): void
    {
        [$shop, $plan] = $this->duePlan();

        Tenant::run($shop, function () use ($plan): void {
            $this->slot($plan, chargedAt: now()->subHours(25), sequence: 1);

            $this->assertTrue(app(ChargeNowService::class)->chargeNow($plan->fresh())->isSucceeded());
        });
    }

    /** The scheduler does not even queue a plan that already moved money today. */
    public function test_the_scheduler_does_not_queue_a_plan_charged_today(): void
    {
        Queue::fake();

        [$shop, $charged] = $this->duePlan('charged.example.com');
        [, $owed] = $this->duePlan('owed.example.com');

        Tenant::run($shop, fn () => $this->slot($charged, chargedAt: now()->subHour(), sequence: 1));

        Tenant::clear(); // the state the scheduler really runs in
        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ChargeJob::class, 1);
        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->planId === (int) $owed->getKey());
    }

    /** The screen: the second "charge now" will not submit until the approval is ticked. */
    public function test_the_screen_requires_an_explicit_approval_for_a_second_charge(): void
    {
        [$shop, $plan] = $this->duePlan();

        Tenant::run($shop, function () use ($shop, $plan): void {
            app(ChargeNowService::class)->chargeNow($plan->fresh());
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('chargeNow', [])
                ->assertHasActionErrors(['approve_repeat']);

            $this->assertSame(1, $this->gatewayCalls, 'no approval, no charge');

            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('chargeNow', ['approve_repeat' => true])
                ->assertHasNoActionErrors();

            $this->assertSame(2, $this->gatewayCalls);
            $approval = ActivityEvent::query()
                ->where('plan_id', $plan->getKey())
                ->where('kind', Timeline::KIND_CHARGE_REPEAT_APPROVED)
                ->sole();
            $this->assertNotSame(ActivityEvent::ACTOR_SYSTEM, $approval->actor);
        });
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function duePlan(string $domain = 'once-a-day.example.com'): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, function () use ($domain): InstallmentPlan {
            $customer = 'cust-'.$domain;

            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => $customer,
                'payplus_card_token_uid' => 'tok-'.$domain,
                'payplus_customer_uid' => 'pp-'.$domain,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => $customer,
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
                'shopify_customer_id' => $customer,
                'customer_name' => 'Dana',
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 39,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => now()->subMinute()->startOfMinute(),
            ])->save();

            return $plan->fresh();
        })];
    }

    private function slot(InstallmentPlan $plan, \DateTimeInterface $chargedAt, int $sequence): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'payment_type' => PaymentType::RECURRING->value,
            'sequence' => $sequence,
            'amount' => 39,
            'currency' => 'ILS',
            'status' => PaymentStatus::SUCCEEDED->value,
            'attempt_count' => 1,
            'charged_at' => $chargedAt,
        ])->save();
    }
}
