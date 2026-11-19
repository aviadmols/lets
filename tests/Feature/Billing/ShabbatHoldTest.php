<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ShabbatHold;
use App\Domain\ShopifySubscriptions\ContractScheduleAdvancer;
use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Filament\Pages\ManageBillingSettings;
use App\Mail\Support\TemplateRenderer;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Jobs\ResumeShabbatHeldChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * A shop that keeps Shabbat takes no automatic payment on a Saturday (Israel
 * time). A charge that falls on one is taken on the Sunday, and the
 * subscription keeps its own date.
 *
 * What must never happen, and is pinned here:
 *   - a charge on a Saturday, including through the scheduler's one-hour early
 *     window on Friday night or a job a busy worker runs after midnight;
 *   - a held charge that is never asked for again;
 *   - a held charge that moves the subscription's day of the month;
 *   - any change at all for a shop that has not switched it on.
 */
final class ShabbatHoldTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const IL = 'Asia/Jerusalem';

    /** Thursday 19/11/2026 is today; these are the Friday, Saturday and Sunday after it. */
    private const SATURDAY_DUE = '2026-11-21 10:00';

    private const SATURDAY_NOON = '2026-11-21 12:00';

    private const FRIDAY_LATE = '2026-11-20 23:30';

    private const SUNDAY_JUST_AFTER = '2026-11-22 00:05';

    private const SUNDAY_MIDDAY = '2026-11-22 10:00';

    private int $gatewayCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gatewayCalls = 0;
        $calls = &$this->gatewayCalls;

        PayPlusGatewayFactory::fake(function (Shop $shop) use (&$calls): PayPlusGatewayInterface {
            return new class($calls) implements PayPlusGatewayInterface
            {
                public function __construct(private int &$calls) {}

                public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
                {
                    $this->calls++;

                    return GatewayResult::fromResponse([
                        'results' => ['status' => 'success', 'code' => 0],
                        'data' => ['transaction' => ['uid' => 'txn-'.$idempotencyKey, 'approval_number' => 'A1']],
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
            };
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === The rule itself ===

    public function test_saturday_is_judged_on_the_israel_clock_not_the_servers(): void
    {
        // 00:30 on Saturday in Israel is still Friday 22:30 in UTC.
        $this->assertTrue(ShabbatHold::isShabbat($this->il('2026-11-21 00:30')));
        $this->assertSame(5, $this->il('2026-11-21 00:30')->utc()->dayOfWeek);

        // 23:30 on Saturday in Israel, and Sunday 00:00 is not Saturday.
        $this->assertTrue(ShabbatHold::isShabbat($this->il('2026-11-21 23:59')));
        $this->assertFalse(ShabbatHold::isShabbat($this->il('2026-11-22 00:00')));
        $this->assertFalse(ShabbatHold::isShabbat($this->il('2026-11-20 23:59')));
    }

    public function test_summer_time_moves_nothing(): void
    {
        // 04/07/2026 is a Saturday in Israel summer time (UTC+3).
        $this->assertTrue($this->il('2026-07-04 00:10')->isSaturday());
        $this->assertTrue(ShabbatHold::isShabbat($this->il('2026-07-04 00:10')));
        $this->assertFalse(ShabbatHold::isShabbat($this->il('2026-07-05 00:00')));
        $this->assertSame('2026-07-05 00:00', ShabbatHold::liftsAt(null, $this->il('2026-07-04 15:00'))->format('Y-m-d H:i'));
    }

    public function test_a_saturday_charge_is_not_pulled_forward_into_friday_night(): void
    {
        $friday = $this->il(self::FRIDAY_LATE);

        // Due at 00:20 on Saturday: the scheduler's early window would reach it now.
        $this->assertTrue(ShabbatHold::holds($this->il('2026-11-21 00:20'), $friday));
        $this->assertSame('2026-11-22 00:00', ShabbatHold::liftsAt($this->il('2026-11-21 00:20'), $friday)->format('Y-m-d H:i'));

        // Due on Friday itself: charged on Friday, as asked.
        $this->assertFalse(ShabbatHold::holds($this->il('2026-11-20 23:50'), $friday));
        $this->assertFalse(ShabbatHold::holds(null, $friday));
    }

    public function test_an_overdue_charge_from_a_past_saturday_is_not_held_on_a_weekday(): void
    {
        $this->assertFalse(ShabbatHold::holds($this->il('2026-11-14 10:00'), $this->il('2026-11-17 10:00')));
        $this->assertFalse(ShabbatHold::holds($this->il(self::SATURDAY_DUE), $this->il(self::SUNDAY_JUST_AFTER)));
    }

    public function test_lateness_is_excused_only_up_to_the_sunday(): void
    {
        $saturday = $this->il(self::SATURDAY_DUE);

        $this->assertTrue(ShabbatHold::excusesLateness($saturday, $this->il('2026-11-22 23:59')));
        $this->assertFalse(ShabbatHold::excusesLateness($saturday, $this->il('2026-11-23 00:00')));
        $this->assertFalse(ShabbatHold::excusesLateness($this->il('2026-11-20 10:00'), $this->il('2026-11-21 12:00')));
    }

    // === The PayPlus scheduler ===

    public function test_on_saturday_only_the_shabbat_shop_is_held(): void
    {
        [$keeper, $keeperPlan] = $this->plan('keeps.example.com', self::SATURDAY_DUE);
        [$other] = $this->plan('other.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper);

        $this->travelTo($this->il(self::SATURDAY_NOON));
        Queue::fake();

        $this->artisan('payplus:dispatch-due')
            ->expectsOutputToContain('held 1 for Shabbat')
            ->assertSuccessful();

        Queue::assertPushed(ChargeJob::class, 1);
        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->shopId === (int) $other->getKey());

        // Nothing was written: the date is where it was, no payment row exists.
        $this->assertSame(
            $this->il(self::SATURDAY_DUE)->utc()->format('Y-m-d H:i'),
            $keeperPlan->fresh()->next_charge_at->utc()->format('Y-m-d H:i'),
        );
        $this->assertSame(0, InstallmentPayment::withoutGlobalScopes()->where('plan_id', $keeperPlan->id)->count());
    }

    public function test_friday_night_does_not_reach_into_saturday(): void
    {
        [$keeper] = $this->plan('fri-keeps.example.com', '2026-11-21 00:20');
        [$other] = $this->plan('fri-other.example.com', '2026-11-21 00:20');
        $this->keepShabbat($keeper);

        $this->travelTo($this->il(self::FRIDAY_LATE));
        Queue::fake();

        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ChargeJob::class, 1);
        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->shopId === (int) $other->getKey());
    }

    public function test_sunday_just_after_midnight_queues_the_held_charge(): void
    {
        [$keeper] = $this->plan('sun-keeps.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper);

        $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
        Queue::fake();

        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->shopId === (int) $keeper->getKey());
    }

    /**
     * The whole weekend, end to end, on the default renewal mode: held on
     * Saturday, charged once on Sunday, next month counted from the SATURDAY.
     */
    public function test_the_sunday_charge_keeps_the_subscriptions_date(): void
    {
        [$keeper, $plan] = $this->plan('e2e-keeps.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper);

        Tenant::run($keeper, function () use ($plan): void {
            $this->travelTo($this->il(self::SATURDAY_NOON));
            Queue::fake();
            (new ChargeJob((int) $plan->shop_id, (int) $plan->id, PaymentType::RECURRING->value))
                ->handle(app(ChargeOrchestrator::class));
            $this->assertSame(0, $this->gatewayCalls);

            $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
            (new ChargeJob((int) $plan->shop_id, (int) $plan->id, PaymentType::RECURRING->value))
                ->handle(app(ChargeOrchestrator::class));
        });

        $this->assertSame(1, $this->gatewayCalls);
        $this->assertSame('2026-12-21 10:00', $plan->fresh()->next_charge_at->setTimezone(self::IL)->format('Y-m-d H:i'));
    }

    // === The wall inside the charge job ===

    public function test_a_job_that_runs_on_saturday_charges_nothing_and_comes_back_on_sunday(): void
    {
        [$keeper, $plan] = $this->plan('wall-keeps.example.com', '2026-11-18 10:00');
        $this->keepShabbat($keeper);

        $this->travelTo($this->il(self::SATURDAY_NOON));
        Queue::fake();

        Tenant::run($keeper, function () use ($plan): void {
            (new ChargeJob((int) $plan->shop_id, (int) $plan->id, PaymentType::RECURRING->value))
                ->handle(app(ChargeOrchestrator::class));
        });

        $this->assertSame(0, $this->gatewayCalls);
        $this->assertSame(0, InstallmentPayment::withoutGlobalScopes()->where('plan_id', $plan->id)->count());

        // Not dropped: queued again for Sunday 00:01, Israel time.
        Queue::assertPushed(ResumeShabbatHeldChargeJob::class, function (ResumeShabbatHeldChargeJob $job) use ($plan): bool {
            return $job->planId === (int) $plan->id
                && CarbonImmutable::instance($job->delay)->setTimezone(self::IL)->format('Y-m-d H:i') === '2026-11-22 00:01';
        });

        $this->assertTrue(ActivityEvent::withoutGlobalScopes()
            ->where('plan_id', $plan->id)
            ->where('kind', Timeline::KIND_CHARGE_HELD_FOR_SHABBAT)
            ->exists());
    }

    /**
     * The case the resume exists for: a STOPPED plan whose customer fixed their
     * card on Saturday. The scheduler never picks a failed plan up again, so the
     * resume is the only thing that asks for that money.
     */
    public function test_a_stopped_plan_held_on_saturday_is_charged_on_sunday(): void
    {
        [$keeper, $plan] = $this->plan('resume-failed.example.com', '2026-11-10 10:00');
        $this->keepShabbat($keeper);
        $this->setStatus($keeper, $plan, PlanStatus::FAILED);

        $resume = $this->holdOnSaturday($keeper, $plan);

        $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
        Queue::fake();
        Tenant::run($keeper, static fn () => $resume->handle());

        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->planId === (int) $plan->id
            && $job->shopId === (int) $keeper->getKey());
    }

    /** Cancelled (or paused) during Shabbat: Sunday does not act on Saturday's decision. */
    public function test_a_plan_cancelled_during_shabbat_is_not_charged_on_sunday(): void
    {
        foreach ([PlanStatus::CANCELLED, PlanStatus::PAUSED] as $i => $after) {
            [$keeper, $plan] = $this->plan("resume-cancel-{$i}.example.com", '2026-11-10 10:00');
            $this->keepShabbat($keeper);
            $this->setStatus($keeper, $plan, PlanStatus::FAILED);

            $resume = $this->holdOnSaturday($keeper, $plan);
            $this->setStatus($keeper, $plan, $after);

            $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
            Queue::fake();
            Tenant::run($keeper, static fn () => $resume->handle());

            Queue::assertNotPushed(ChargeJob::class);
        }
    }

    public function test_a_plan_rescheduled_during_shabbat_is_not_charged_on_sunday(): void
    {
        [$keeper, $plan] = $this->plan('resume-moved.example.com', '2026-11-10 10:00');
        $this->keepShabbat($keeper);
        $this->setStatus($keeper, $plan, PlanStatus::FAILED);

        $resume = $this->holdOnSaturday($keeper, $plan);
        Tenant::run($keeper, static fn () => $plan->fresh()->forceFill(['next_charge_at' => now()->addMonth()])->save());

        $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
        Queue::fake();
        Tenant::run($keeper, static fn () => $resume->handle());

        Queue::assertNotPushed(ChargeJob::class);
    }

    /**
     * A plan the scheduler collects itself is left to it: its retry backoff and
     * once-a-day filters decide, so a card the scheduler's 00:00 attempt was just
     * declined on is not asked again at 00:01.
     */
    public function test_a_chargeable_plan_is_left_to_the_scheduler(): void
    {
        [$keeper, $plan] = $this->plan('resume-active.example.com', '2026-11-10 10:00');
        $this->keepShabbat($keeper);

        $resume = $this->holdOnSaturday($keeper, $plan);

        $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
        Queue::fake();
        Tenant::run($keeper, static fn () => $resume->handle());

        Queue::assertNotPushed(ChargeJob::class);
    }

    /** Still active, a failed slot owed, next date in the future: the scheduler would not ask until then. */
    public function test_an_owed_slot_the_scheduler_would_not_see_yet_is_asked_for_on_sunday(): void
    {
        [$keeper, $plan] = $this->plan('resume-owed.example.com', '2026-12-10 10:00');
        $this->keepShabbat($keeper);
        Tenant::run($keeper, static fn () => $plan->fresh()->forceFill(['payment_failed_at' => now()->subDays(3)])->save());

        // Held by the ChargeJob path (a card update), not by the scheduler.
        $this->travelTo($this->il(self::SATURDAY_NOON));
        Queue::fake();
        Tenant::run($keeper, static function () use ($plan): void {
            (new ChargeJob((int) $plan->shop_id, (int) $plan->id, PaymentType::RECURRING->value))
                ->handle(app(ChargeOrchestrator::class));
        });
        $resume = null;
        Queue::assertPushed(ResumeShabbatHeldChargeJob::class, function (ResumeShabbatHeldChargeJob $job) use (&$resume): bool {
            $resume = $job;

            return true;
        });

        $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
        Queue::fake();
        Tenant::run($keeper, static fn () => $resume->handle());

        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->planId === (int) $plan->id);
    }

    /** A card update on a TUESDAY is not held for days just because the next date is this Saturday. */
    public function test_the_friday_window_is_bounded(): void
    {
        $saturday = $this->il('2026-11-21 10:00');

        $this->assertFalse(ShabbatHold::holds($saturday, $this->il('2026-11-17 12:00')));
        $this->assertFalse(ShabbatHold::holds($saturday, $this->il('2026-11-20 22:30')));
        $this->assertTrue(ShabbatHold::holds($saturday, $this->il('2026-11-20 23:00')));
    }

    public function test_a_shop_that_never_switched_it_on_charges_on_saturday_as_before(): void
    {
        [$shop, $plan] = $this->plan('untouched.example.com', self::SATURDAY_DUE);

        $this->travelTo($this->il(self::SATURDAY_NOON));
        Queue::fake();

        Tenant::run($shop, function () use ($plan): void {
            $this->assertFalse(MerchantBillingSettings::current()->observesShabbat());
            (new ChargeJob((int) $plan->shop_id, (int) $plan->id, PaymentType::RECURRING->value))
                ->handle(app(ChargeOrchestrator::class));
        });

        $this->assertSame(1, $this->gatewayCalls);
        Queue::assertNotPushed(ResumeShabbatHeldChargeJob::class);
    }

    public function test_a_person_pressing_charge_now_on_saturday_is_not_held(): void
    {
        [$keeper, $plan] = $this->plan('manual-keeps.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper);

        $this->travelTo($this->il(self::SATURDAY_NOON));

        Tenant::run($keeper, function () use ($plan): void {
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());
        });

        $this->assertSame(1, $this->gatewayCalls);
    }

    // === The renewal modes: the date never moves ===

    /** "By date" would count from the Sunday; a Shabbat hold is not lateness. */
    public function test_by_date_mode_keeps_the_saturday_anniversary(): void
    {
        [$keeper, $plan] = $this->plan('bydate-keeps.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper, MerchantBillingSettings::ANCHOR_CHARGE_DATE);

        $this->travelTo($this->il(self::SUNDAY_MIDDAY));
        Tenant::run($keeper, function () use ($plan): void {
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());
        });

        $this->assertSame('2026-12-21 10:00', $plan->fresh()->next_charge_at->setTimezone(self::IL)->format('Y-m-d H:i'));
    }

    /** The same Sunday charge, shop NOT keeping Shabbat: by-date behaves exactly as it always did. */
    public function test_by_date_mode_is_unchanged_for_a_shop_that_does_not_keep_shabbat(): void
    {
        [$shop, $plan] = $this->plan('bydate-other.example.com', self::SATURDAY_DUE);
        Tenant::run($shop, static function (): void {
            MerchantBillingSettings::current()->forceFill(['renewal_anchor' => MerchantBillingSettings::ANCHOR_CHARGE_DATE])->save();
        });

        $this->travelTo($this->il(self::SUNDAY_MIDDAY));
        Tenant::run($shop, function () use ($plan): void {
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());
        });

        $this->assertSame('2026-12-22 00:00', $plan->fresh()->next_charge_at->utc()->format('Y-m-d H:i'));
    }

    /** A Monday charge for a Saturday cycle really was late (it failed on Sunday). */
    public function test_by_date_mode_still_counts_real_lateness(): void
    {
        [$keeper, $plan] = $this->plan('bydate-late.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper, MerchantBillingSettings::ANCHOR_CHARGE_DATE);

        $this->travelTo($this->il('2026-11-23 12:00'));
        Tenant::run($keeper, function () use ($plan): void {
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());
        });

        $this->assertSame('2026-12-23 00:00', $plan->fresh()->next_charge_at->utc()->format('Y-m-d H:i'));
    }

    public function test_skip_missed_mode_keeps_the_saturday_anniversary(): void
    {
        [$keeper, $plan] = $this->plan('skip-keeps.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper, MerchantBillingSettings::ANCHOR_SKIP_MISSED);

        $this->travelTo($this->il(self::SUNDAY_MIDDAY));
        Tenant::run($keeper, function () use ($plan): void {
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());
        });

        $this->assertSame('2026-12-21 10:00', $plan->fresh()->next_charge_at->setTimezone(self::IL)->format('Y-m-d H:i'));
    }

    // === The Shopify Payments rail ===

    public function test_shopify_contracts_wait_for_sunday_too(): void
    {
        $keeper = $this->shopifyShop('keeps-shopify.myshopify.com');
        $other = $this->shopifyShop('other-shopify.myshopify.com');
        $this->keepShabbat($keeper);

        $held = $this->contract($keeper, '2026-11-21 08:00:00', 'gid://shopify/SubscriptionContract/501');
        $this->contract($other, '2026-11-21 08:00:00', 'gid://shopify/SubscriptionContract/502');

        $this->travelTo($this->il(self::SATURDAY_NOON));
        Queue::fake();
        $this->artisan('shopify-subscriptions:dispatch-due')
            ->expectsOutputToContain('held 1 for Shabbat')
            ->assertSuccessful();

        Queue::assertPushed(BillingAttemptJob::class, 1);
        Queue::assertPushed(BillingAttemptJob::class, fn (BillingAttemptJob $job): bool => $job->shopId === (int) $other->getKey()
            && $job->automatic);

        $this->travelTo($this->il(self::SUNDAY_JUST_AFTER));
        Queue::fake();
        $this->artisan('shopify-subscriptions:dispatch-due')->assertSuccessful();

        Queue::assertPushed(BillingAttemptJob::class, fn (BillingAttemptJob $job): bool => $job->contractId === (int) $held->getKey());
    }

    public function test_a_shopify_attempt_the_scan_queued_before_midnight_stops_before_its_row(): void
    {
        $keeper = $this->shopifyShop('late-shopify.myshopify.com');
        $this->keepShabbat($keeper);
        $contract = $this->contract($keeper, '2026-11-20 21:00:00', 'gid://shopify/SubscriptionContract/601');

        $this->travelTo($this->il('2026-11-21 00:00')->addSeconds(5));

        $recorder = new RecordingShopifyClient;
        $recorder->graphqlResponses = [['data' => ['subscriptionBillingAttemptCreate' => [
            'subscriptionBillingAttempt' => ['id' => 'gid://shopify/SubscriptionBillingAttempt/1'],
            'userErrors' => [],
        ]]]];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $job = new BillingAttemptJob((int) $keeper->getKey(), (int) $contract->getKey(), '2026-11-20', automatic: true);
        Tenant::run($keeper, static fn () => $job->handle());

        $this->assertSame(0, SubscriptionBillingAttempt::withoutGlobalScopes()->where('subscription_contract_id', $contract->getKey())->count());
        $this->assertCount(0, $recorder->graphqlCalls);

        // The control: the SAME job at the same moment, from a person's "bill now",
        // does go through. So the wall above is what stopped the automatic one.
        $manual = new BillingAttemptJob((int) $keeper->getKey(), (int) $contract->getKey(), '2026-11-20');
        Tenant::run($keeper, static fn () => $manual->handle());

        $this->assertSame(1, SubscriptionBillingAttempt::withoutGlobalScopes()->where('subscription_contract_id', $contract->getKey())->count());
        $this->assertCount(1, $recorder->graphqlCalls);

        ShopifyClientFactory::clearFake();
    }

    /**
     * A job queued BEFORE the deploy carries no `automatic` key. It must read as
     * false (a person's "bill now", runs as queued), not throw on an
     * uninitialized property and fail with tries = 1.
     */
    public function test_a_billing_attempt_queued_before_the_flag_existed_still_runs(): void
    {
        $old = (new \ReflectionClass(BillingAttemptJob::class))->newInstanceWithoutConstructor();

        $this->assertFalse($old->automatic);
    }

    /** The Shopify rail's "by date" mode keeps the Saturday anniversary too. */
    public function test_shopify_by_date_mode_keeps_the_saturday_anniversary(): void
    {
        $keeper = $this->shopifyShop('advance-keeps.myshopify.com');
        $this->keepShabbat($keeper, MerchantBillingSettings::ANCHOR_CHARGE_DATE);
        $contract = $this->contract($keeper, '2026-11-21 08:00:00', 'gid://shopify/SubscriptionContract/701');

        // Sunday afternoon: well past 00:00 UTC, where "today" really is Sunday.
        $this->travelTo($this->il('2026-11-22 15:00'));

        $next = Tenant::run($keeper, fn () => app(ContractScheduleAdvancer::class)
            ->nextDate($contract, \Illuminate\Support\Carbon::parse('2026-11-21 08:00:00', 'UTC')));

        $this->assertSame('2026-12-21', $next->utc()->toDateString());
    }

    // === What the customer reads ===

    public function test_the_reminder_names_the_sunday(): void
    {
        [$keeper, $plan] = $this->plan('mail-keeps.example.com', self::SATURDAY_DUE);
        [$other, $otherPlan] = $this->plan('mail-other.example.com', self::SATURDAY_DUE);
        $this->keepShabbat($keeper);

        $this->assertSame('22/11/2026', TemplateRenderer::planVars($plan->fresh(), 'Shop')['next_charge_date']);
        $this->assertSame('21/11/2026', TemplateRenderer::planVars($otherPlan->fresh(), 'Shop')['next_charge_date']);
    }

    // === The switch ===

    public function test_the_settings_screen_saves_the_switch(): void
    {
        [$shop] = $this->plan('screen-keeps.example.com', self::SATURDAY_DUE);
        $user = User::factory()->forShop($shop)->create();

        Tenant::set($shop);
        $this->actingAs($user);

        Livewire::test(ManageBillingSettings::class)
            ->assertSet('data.observes_shabbat', false)
            ->set('data.observes_shabbat', true)
            ->call('save');

        $this->assertTrue(MerchantBillingSettings::current()->fresh()->observesShabbat());
        $this->assertSame([(int) $shop->getKey() => true], ShabbatHold::observingShops());
    }

    // === Fixtures ===

    private function il(string $wallClock): CarbonImmutable
    {
        return CarbonImmutable::parse($wallClock, self::IL);
    }

    /** Run the plan's ChargeJob on Saturday noon and hand back the resume it queued. */
    private function holdOnSaturday(Shop $shop, InstallmentPlan $plan): ResumeShabbatHeldChargeJob
    {
        $this->travelTo($this->il(self::SATURDAY_NOON));
        Queue::fake();

        Tenant::run($shop, static function () use ($plan): void {
            (new ChargeJob((int) $plan->shop_id, (int) $plan->id, PaymentType::RECURRING->value))
                ->handle(app(ChargeOrchestrator::class));
        });

        $resume = null;
        Queue::assertPushed(ResumeShabbatHeldChargeJob::class, function (ResumeShabbatHeldChargeJob $job) use (&$resume): bool {
            $resume = $job;

            return true;
        });
        $this->assertSame(0, $this->gatewayCalls);

        return $resume;
    }

    private function setStatus(Shop $shop, InstallmentPlan $plan, PlanStatus $status): void
    {
        Tenant::run($shop, static fn () => $plan->fresh()->forceFill(['status' => $status->value])->save());
    }

    private function keepShabbat(Shop $shop, ?string $anchor = null): void
    {
        Tenant::run($shop, static function () use ($anchor): void {
            MerchantBillingSettings::current()->forceFill(array_filter([
                'observes_shabbat' => true,
                'renewal_anchor' => $anchor,
            ], static fn ($v): bool => $v !== null))->save();
        });
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function plan(string $domain, string $dueIsrael): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, function () use ($domain, $dueIsrael): InstallmentPlan {
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
                'accepted_at' => now()->subYear(),
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
                'customer_email' => 'dana@'.$domain,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 39,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => $this->il($dueIsrael)->utc(),
            ])->save();

            return $plan->fresh();
        })];
    }

    private function shopifyShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function contract(Shop $shop, string $nextBillingUtc, string $gid): SubscriptionContract
    {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => $gid,
            'shopify_customer_gid' => 'gid://shopify/Customer/77',
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => CarbonImmutable::parse($nextBillingUtc, 'UTC'),
            'currency' => 'ILS',
            'amount' => 73.00,
            'synced_at' => now(),
        ])->save();

        return $contract->fresh();
    }
}
