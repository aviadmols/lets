<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ChargingResumeService;
use App\Filament\Pages\ManageBillingSettings;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
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
 * The master tap on a shop's saved-token money.
 *
 * A store mid-migration needs its subscriptions ACTIVE and readable long before it
 * wants a card touched. These cover the two halves of that: that "off" really is
 * off — at the one place every automatic charge passes through, with nothing
 * written on the way out — and that turning it back on cannot bill months of
 * elapsed cycles in a single minute.
 */
final class LiveChargingSwitchTest extends TestCase
{
    use RefreshDatabase;

    public int $payplusCallCount = 0;

    public function recordPayplusCall(): int
    {
        return ++$this->payplusCallCount;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->payplusCallCount = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private LiveChargingSwitchTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = $this->test->recordPayplusCall();

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

    // === Off means off ===

    /**
     * Nothing written on the way out. A ledger row or a payment row left behind by
     * a charge that never happened is exactly the debris a resumed shop would have
     * to reconcile later.
     */
    public function test_a_paused_shop_charges_nothing_and_writes_nothing(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('paused-shop.myshopify.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();

            $outcome = app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $this->assertSame(ChargeOutcome::RESULT_SKIPPED, $outcome->result);
            $this->assertSame('charging_paused', $outcome->reason);
        });

        $this->assertSame(0, $this->payplusCallCount);
        $this->assertSame(0, PaymentLedger::where('shop_id', $shop->id)->count());
    }

    /** A subscription that quietly did not bill must be discoverable afterwards. */
    public function test_the_skip_is_recorded_on_the_plans_timeline(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('paused-timeline.myshopify.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $this->assertSame(
                1,
                ActivityEvent::query()
                    ->where('plan_id', $plan->id)
                    ->where('kind', Timeline::KIND_CHARGING_PAUSED)
                    ->count(),
            );
        });
    }

    /** The default must be indistinguishable from the behaviour before the switch. */
    public function test_a_shop_that_never_touched_the_switch_still_charges(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('live-shop.myshopify.com');

        Tenant::run($shop, function () use ($plan): void {
            $this->assertTrue(MerchantBillingSettings::current()->chargingIsLive());

            $outcome = app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $this->assertTrue($outcome->isSucceeded());
        });

        $this->assertSame(1, $this->payplusCallCount);
    }

    /** The switch is per shop. One merchant's hold must not stop another's money. */
    public function test_the_scheduler_skips_only_the_paused_shop(): void
    {
        [$paused, $pausedPlan] = $this->recurringPlanWithToken('sched-paused.myshopify.com');
        [$live, $livePlan] = $this->recurringPlanWithToken('sched-live.myshopify.com');

        Tenant::run($paused, static function (): void {
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();
        });

        foreach ([[$paused, $pausedPlan], [$live, $livePlan]] as [$shop, $plan]) {
            Tenant::run($shop, static function () use ($plan): void {
                $plan->forceFill(['next_charge_at' => now()->subMinute()])->save();
            });
        }

        Queue::fake();

        $this->artisan('payplus:dispatch-due')
            ->expectsOutputToContain('skipped 1 on shops with live charging off')
            ->assertSuccessful();

        Queue::assertPushed(
            ChargeJob::class,
            1,
        );
    }

    // === Turning it back on ===

    public function test_resuming_rolls_an_overdue_date_forward_by_whole_cycles(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('resume-roll.myshopify.com');

        Tenant::run($shop, function () use ($shop, $plan): void {
            // Three monthly cycles missed while charging was off.
            $plan->forceFill(['next_charge_at' => now()->subMonths(3)->startOfDay()])->save();

            $report = app(ChargingResumeService::class)->resume($shop, write: true);

            $this->assertSame(1, $report['overdue']);
            $this->assertSame(1, $report['rolled']);

            $when = $plan->fresh()->next_charge_at;
            $this->assertTrue($when->isFuture(), 'an overdue date must land in the future');
            // Rolled by whole cycles — never "today", never the elapsed cycles billed.
            $this->assertSame(now()->subMonths(3)->startOfDay()->day, $when->day);
        });
    }

    public function test_a_future_date_is_left_exactly_where_it_was(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('resume-future.myshopify.com');

        Tenant::run($shop, function () use ($shop, $plan): void {
            $future = now()->addDays(9)->startOfDay();
            $plan->forceFill(['next_charge_at' => $future])->save();

            $report = app(ChargingResumeService::class)->resume($shop, write: true);

            $this->assertSame(0, $report['rolled']);
            $this->assertSame(1, $report['unchanged']);
            $this->assertSame($future->toDateString(), $plan->fresh()->next_charge_at->toDateString());
        });
    }

    /** A preview writes nothing — the same discipline as the import's release. */
    public function test_a_preview_moves_no_dates(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('resume-preview.myshopify.com');

        Tenant::run($shop, function () use ($shop, $plan): void {
            $was = now()->subMonths(2)->startOfDay();
            $plan->forceFill(['next_charge_at' => $was])->save();

            $report = app(ChargingResumeService::class)->resume($shop);

            $this->assertSame(1, $report['rolled'], 'the preview still reports what it would do');
            $this->assertFalse($report['committed']);
            $this->assertSame($was->toDateString(), $plan->fresh()->next_charge_at->toDateString());
        });
    }

    /** No cadence, nothing to roll by. It must be left alone, not crashed on. */
    public function test_a_plan_without_a_frequency_survives_the_resume(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('resume-nofreq.myshopify.com');

        Tenant::run($shop, function () use ($shop, $plan): void {
            $was = now()->subMonth()->startOfDay();
            $plan->forceFill(['next_charge_at' => $was, 'billing_frequency' => null])->save();

            $report = app(ChargingResumeService::class)->resume($shop, write: true);

            $this->assertSame(1, $report['overdue']);
            $this->assertSame(0, $report['rolled']);
            $this->assertSame($was->toDateString(), $plan->fresh()->next_charge_at->toDateString());
        });
    }

    // === The screen the merchant actually flips it from ===

    /**
     * Saving the settings page is where the two halves meet: the switch is written
     * FIRST, and only then are overdue dates rolled — a plan must never be handed a
     * fresh date while the shop is still recorded as not charging.
     */
    public function test_saving_the_screen_pauses_then_resumes_and_rolls_on_the_way_back(): void
    {
        [$shop, $plan] = $this->recurringPlanWithToken('screen-switch.myshopify.com');
        $user = User::factory()->forShop($shop)->create();

        Tenant::set($shop);
        $this->actingAs($user);

        // OFF: stamped, so "since when was nobody charged?" has an answer.
        Livewire::test(ManageBillingSettings::class)
            ->set('data.live_charging_enabled', false)
            ->call('save');

        $settings = MerchantBillingSettings::current()->fresh();
        $this->assertFalse($settings->chargingIsLive());
        $this->assertNotNull($settings->charging_paused_at);

        // A cycle elapses while the shop is off.
        $plan->forceFill(['next_charge_at' => now()->subMonths(2)->startOfDay()])->save();

        // ON: the overdue date is rolled rather than billed.
        Livewire::test(ManageBillingSettings::class)
            ->set('data.live_charging_enabled', true)
            ->call('save');

        $this->assertTrue(MerchantBillingSettings::current()->fresh()->chargingIsLive());
        $this->assertTrue($plan->fresh()->next_charge_at->isFuture());
    }

    // === Helpers ===

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function recurringPlanWithToken(string $domain): array
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, static function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-cust-1',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-cust-1',
                'installment_amount' => 49.90,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'next_charge_at' => now(),
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        })];
    }
}
