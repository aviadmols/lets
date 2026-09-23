<?php

namespace Tests\Feature\Billing;

use App\Domain\Dashboard\AnalyticsMetrics;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Pages\HomeDashboard;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Jobs\SendReminderEmailJob;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A SUBSCRIBER THE SHOP GIVES AWAY IS NEVER ASKED FOR MONEY.
 *
 * The admin can add one by hand (ManualSubscriptionService). The first version of
 * that feature made the promise out of a SHAPE — no charge date, no card, no
 * consent row — and every part of that shape came back off an ordinary click:
 *
 *   - Resume mints a clock for a plan that has none (a real fix, for migrated
 *     members), and the date verbs hand one over on request;
 *   - the card-update link attaches a card to a plan that had none;
 *   - the consent gate matches the CUSTOMER, not the plan, so comping somebody
 *     who already subscribes passes it on the consent they gave for the
 *     subscription they PAY for;
 *   - and a plan with no card never reaches that gate anyway — it falls into
 *     manual-payment mode first, which emails the customer an invoice and
 *     advances their cycle.
 *
 * So the promise is a column the engine reads, and these are the tests that hold
 * it: each one hands the plan back exactly the thing the old design assumed it
 * would never have, and then asks the engine to charge it.
 */
final class NoChargePlanTest extends TestCase
{
    use RefreshDatabase;

    public int $gatewayCalls = 0;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private NoChargePlanTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->gatewayCalls;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n]],
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

        $this->shop = Shop::create([
            'woocommerce_domain' => 'comped.example.com',
            'name' => 'Comped',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $this->shop->payplus_credentials = [
            'api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't', 'payment_page_uid' => 'p',
        ];
        // What the card-update link needs on the shop side, so the only thing
        // left to decide the answer is the plan itself.
        $this->shop->callback_token = 'cb-token';
        $this->shop->save();
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    /**
     * The worst case, and the one the old design could not survive: a comped plan
     * that has since been given a card, a clock, AND whose owner already consented
     * to be charged for the subscription they pay for.
     */
    public function test_it_is_refused_even_with_a_card_a_clock_and_the_customer_s_consent(): void
    {
        $plan = $this->plan(noCharge: true, withCard: true, withConsent: true, due: true);

        $outcome = Tenant::run($this->shop, fn (): ChargeOutcome => app(ChargeOrchestrator::class)
            ->charge($plan->id, PaymentType::RECURRING));

        $this->assertSame(ChargeOutcome::RESULT_SKIPPED, $outcome->result);
        $this->assertSame('no_charge_plan', $outcome->reason);
        $this->assertSame(0, $this->gatewayCalls, 'PayPlus is never asked');

        // Refused BEFORE anything is written: no money row, no slot, and not even
        // a charge attempt — there was no attempt.
        $this->assertDatabaseCount('payment_ledger', 0);
        $this->assertDatabaseCount('installment_payments', 0);
        $this->assertDatabaseMissing('activity_events', [
            'plan_id' => $plan->getKey(),
            'kind' => 'charge_attempt_started',
        ]);
        $this->assertDatabaseHas('activity_events', [
            'plan_id' => $plan->getKey(),
            'kind' => ChargeOrchestrator::KIND_NO_CHARGE_PLAN,
        ]);
    }

    /**
     * With no card, the old code routed the plan into manual-payment mode — which
     * mails the customer "please pay" and rolls their cycle forward. A comped
     * member would have been dunned for money nobody meant to ask them for.
     */
    public function test_it_is_not_sent_a_manual_payment_invoice(): void
    {
        Mail::fake();

        $plan = $this->plan(noCharge: true, withCard: false, withConsent: false, due: true);
        $due = $plan->next_charge_at->copy();

        $outcome = Tenant::run($this->shop, fn (): ChargeOutcome => app(ChargeOrchestrator::class)
            ->charge($plan->id, PaymentType::RECURRING));

        $this->assertSame('no_charge_plan', $outcome->reason);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('activity_events', [
            'plan_id' => $plan->getKey(),
            'kind' => 'manual_payment_email_sent',
        ]);

        // And the clock did not move: manual mode advances it, which would have
        // walked a free member through a cycle every month forever.
        $this->assertTrue($plan->fresh()->next_charge_at->isSameDay($due));
    }

    /** Even carrying a clock, it is never selected — no job, no wasted dispatch. */
    public function test_the_scheduler_does_not_queue_it(): void
    {
        Queue::fake();

        $this->plan(noCharge: true, withCard: true, withConsent: true, due: true);

        Tenant::clear(); // the state the scheduler really runs in
        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertNotPushed(ChargeJob::class);
    }

    /**
     * Resume means "they are a member again", never "start collecting today" —
     * the one click that used to hand a comped plan a same-day charge date.
     */
    public function test_resuming_it_does_not_mint_a_charge_date(): void
    {
        $plan = $this->plan(noCharge: true, withCard: false, withConsent: false, due: false);

        Tenant::run($this->shop, function () use ($plan): void {
            $paused = app(SubscriptionLifecycleService::class)->pause($plan->fresh());
            $resumed = app(SubscriptionLifecycleService::class)->resume($paused->fresh());

            $this->assertSame(PlanStatus::ACTIVE, $resumed->status);
            $this->assertNull($resumed->next_charge_at, 'a comped member keeps no clock');
        });
    }

    /** A paying subscription still gets its clock back — the fix is not a regression. */
    public function test_resuming_an_ordinary_subscription_still_mints_one(): void
    {
        $plan = $this->plan(noCharge: false, withCard: true, withConsent: true, due: false);

        Tenant::run($this->shop, function () use ($plan): void {
            $paused = app(SubscriptionLifecycleService::class)->pause($plan->fresh());
            $resumed = app(SubscriptionLifecycleService::class)->resume($paused->fresh());

            $this->assertNotNull($resumed->next_charge_at);
        });
    }

    /**
     * A cycle worth nothing is refused on its own account — every path that
     * CREATES a plan rejects a zero amount, but an import, an edit or a price of
     * zero could still arrive at collection time, and PayPlus declines ₪0.00.
     * That decline reads as a failed cycle and starts a dunning ladder against a
     * customer whose card is perfectly fine.
     */
    public function test_a_cycle_worth_nothing_is_refused_before_the_gateway(): void
    {
        $plan = $this->plan(noCharge: false, withCard: true, withConsent: true, due: true, amount: 0);
        $due = $plan->next_charge_at->copy();

        $outcome = Tenant::run($this->shop, fn (): ChargeOutcome => app(ChargeOrchestrator::class)
            ->charge($plan->id, PaymentType::RECURRING));

        $this->assertSame(ChargeOutcome::RESULT_SKIPPED, $outcome->result);
        $this->assertSame('zero_amount', $outcome->reason);
        $this->assertSame(0, $this->gatewayCalls);
        $this->assertDatabaseCount('payment_ledger', 0);

        // …and the cycle moves on. A refusal that left the date alone would be
        // re-dispatched every five minutes for the life of the plan.
        $this->assertTrue($plan->fresh()->next_charge_at->isSameDay($due->copy()->addMonth()));
    }

    /** The same refusal on the scheduler's own terms: once, not every five minutes. */
    public function test_a_zero_amount_plan_is_not_re_dispatched_for_ever(): void
    {
        Queue::fake();

        $plan = $this->plan(noCharge: false, withCard: true, withConsent: true, due: true, amount: 0);

        Tenant::run($this->shop, fn (): ChargeOutcome => app(ChargeOrchestrator::class)
            ->charge($plan->id, PaymentType::RECURRING));

        Tenant::clear();
        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertNotPushed(ChargeJob::class);
    }

    /**
     * No reminder states a sum for a subscription that will never be billed.
     *
     * Both plans carry a clock inside the reminder window — the comped one has a
     * date it should not have, precisely so the scan is judged on the PLAN and
     * not on the absence of a date — and only the paying one is reminded.
     */
    public function test_it_is_never_sent_a_charge_reminder(): void
    {
        Queue::fake();

        $comped = $this->plan(noCharge: true, withCard: false, withConsent: false, due: false);
        $paying = $this->plan(noCharge: false, withCard: true, withConsent: true, due: false);

        Tenant::run($this->shop, function () use ($comped, $paying): void {
            foreach ([$comped, $paying] as $plan) {
                $plan->forceFill(['next_charge_at' => now()->addHours(2)])->save();
            }
        });

        Tenant::clear();
        $this->artisan('payplus:dispatch-reminders')->assertSuccessful();

        Queue::assertPushed(SendReminderEmailJob::class, 1);
        Queue::assertPushed(
            SendReminderEmailJob::class,
            fn (SendReminderEmailJob $job): bool => $job->planId === (int) $paying->getKey(),
        );
    }

    /** Nor does the merchant's home screen count it as money coming in. */
    public function test_it_is_not_listed_as_an_upcoming_charge(): void
    {
        $comped = $this->plan(noCharge: true, withCard: false, withConsent: false, due: false, amount: 80);
        Tenant::run($this->shop, fn () => $comped->forceFill(['next_charge_at' => now()->addDay()])->save());

        $paying = $this->plan(noCharge: false, withCard: true, withConsent: true, due: false);
        Tenant::run($this->shop, fn () => $paying->forceFill(['next_charge_at' => now()->addDay()])->save());

        Tenant::run($this->shop, function () use ($paying): void {
            $rows = Livewire::test(HomeDashboard::class)->instance()->upcomingCharges();

            $this->assertCount(1, $rows);
            $this->assertStringContainsString(
                (string) $paying->customer_name,
                $rows[0]['customer'],
            );
        });
    }

    /** Asking a comped member to keep a card current is collecting one for nothing. */
    public function test_it_is_not_offered_a_card_update_link(): void
    {
        $comped = $this->plan(noCharge: true, withCard: false, withConsent: false, due: false);
        $paying = $this->plan(noCharge: false, withCard: true, withConsent: true, due: false);

        Tenant::run($this->shop, function () use ($comped, $paying): void {
            $this->assertFalse(CardUpdateService::availableFor($this->shop, $comped));
            $this->assertTrue(CardUpdateService::availableFor($this->shop, $paying));
        });
    }

    /** They are a subscriber. They are not revenue. */
    public function test_it_counts_as_a_subscriber_but_never_as_revenue(): void
    {
        $this->plan(noCharge: true, withCard: false, withConsent: false, due: false, amount: 120);

        Tenant::run($this->shop, function (): void {
            $metrics = AnalyticsMetrics::forRange(30);

            $this->assertSame(1, $metrics['active_subscriptions']);
            $this->assertSame(0.0, $metrics['mrr']);
        });
    }

    private function plan(
        bool $noCharge,
        bool $withCard,
        bool $withConsent,
        bool $due,
        float $amount = 39,
    ): InstallmentPlan {
        return Tenant::run($this->shop, function () use ($noCharge, $withCard, $withConsent, $due, $amount): InstallmentPlan {
            $customer = 'cust-'.uniqid();

            $method = $withCard ? InstallmentPaymentMethod::create([
                'shopify_customer_id' => $customer,
                'payplus_card_token_uid' => 'tok-'.$customer,
                'payplus_customer_uid' => 'pp-'.$customer,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]) : null;

            if ($withConsent) {
                CustomerConsent::create([
                    'shopify_customer_id' => $customer,
                    'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                    'accepted_at' => now(),
                ]);
            }

            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => Tenant::id(),
                'public_id' => 'PLN-'.uniqid(),
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'no_charge' => $noCharge,
                'payment_method_id' => $method?->id,
                'shopify_customer_id' => $customer,
                'customer_name' => 'Dana',
                'customer_email' => $customer.'@example.com',
                'total_amount' => $amount,
                'total_charged' => 0,
                'installment_amount' => $amount,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => $due ? now()->subMinute()->startOfMinute() : null,
            ])->save();

            return $plan->fresh();
        });
    }
}
