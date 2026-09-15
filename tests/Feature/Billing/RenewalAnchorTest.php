<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\ManageBillingSettings;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Charging after a delay" — what a renewal counts from when a charge lands
 * late, after a card that was declined for months.
 *
 * PER CYCLE (the default, every shop's behaviour until now): the schedule is
 * the debt. The next date is a cycle after the one just settled, so a plan held
 * for three months collects all three and keeps its anniversary.
 *
 * BY DATE: the customer pays for the cycle being charged and the next one is a
 * cycle from today. The months they got nothing for are not collected.
 *
 * Read at ONE place, so the two rails and every trigger agree.
 */
final class RenewalAnchorTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    /** The day the cycle was owed, and a "now" two months past it. */
    private const OWED = '2026-01-14 00:00:00';

    private const LATE_NOW = '2026-03-16 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
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
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    /** The default is the behaviour every shop already has: the schedule is the debt. */
    public function test_per_cycle_counts_the_next_renewal_from_the_cycle_that_was_owed(): void
    {
        [$shop, $plan] = $this->plan('anchor-cycle.example.com');

        Tenant::run($shop, function () use ($plan): void {
            $this->assertSame(MerchantBillingSettings::ANCHOR_CYCLE, MerchantBillingSettings::current()->renewalAnchor());

            $this->travelTo(CarbonImmutable::parse(self::LATE_NOW));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            // February — still in the past, so the missed months will be collected.
            $this->assertSame('2026-02-14', $plan->fresh()->next_charge_at->format('Y-m-d'));
        });
    }

    /** By date: one cycle from today; the months with no service are not collected. */
    public function test_by_date_counts_the_next_renewal_from_today_when_the_charge_is_late(): void
    {
        [$shop, $plan] = $this->plan('anchor-date.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['renewal_anchor' => MerchantBillingSettings::ANCHOR_CHARGE_DATE])->save();

            $this->travelTo(CarbonImmutable::parse(self::LATE_NOW));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            $this->assertSame('2026-04-16 00:00', $plan->fresh()->next_charge_at->format('Y-m-d H:i'));
        });
    }

    /**
     * On time is NOT late. The scheduler charges up to an hour early, at 23:05
     * the night before; anchored on that timestamp the renewal would creep an
     * hour earlier every month and eventually a whole day. The schedule wins
     * whenever today is not past it.
     */
    public function test_by_date_keeps_the_schedule_for_a_charge_made_on_time(): void
    {
        [$shop, $plan] = $this->plan('anchor-ontime.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['renewal_anchor' => MerchantBillingSettings::ANCHOR_CHARGE_DATE])->save();

            // The night before, inside the early window.
            $this->travelTo(CarbonImmutable::parse(self::OWED)->subMinutes(30));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            $this->assertSame('2026-02-14 00:00', $plan->fresh()->next_charge_at->format('Y-m-d H:i'));
        });
    }

    /**
     * Per cycle WITHOUT collecting what was missed: the anniversary is kept and
     * the months the card was dead are forgiven — and said on the plan.
     */
    public function test_skip_missed_keeps_the_billing_day_and_forgives_the_missed_cycles(): void
    {
        [$shop, $plan] = $this->plan('anchor-skip.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['renewal_anchor' => MerchantBillingSettings::ANCHOR_SKIP_MISSED])->save();

            $this->travelTo(CarbonImmutable::parse(self::LATE_NOW));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            // Still the 14th — the first 14th after today. February and March are gone.
            $this->assertSame('2026-04-14 00:00', $plan->fresh()->next_charge_at->format('Y-m-d H:i'));

            $forgiven = ActivityEvent::query()
                ->where('plan_id', $plan->id)
                ->where('kind', Timeline::KIND_CYCLES_FORGIVEN)
                ->sole();
            $this->assertSame(2, (int) $forgiven->details['skipped']);
            $this->assertSame('2026-01-14', $forgiven->details['from']);
            $this->assertSame('2026-04-14', $forgiven->details['to']);
        });
    }

    /** On time, nothing is forgiven and nothing is said: the ordinary next cycle. */
    public function test_skip_missed_on_time_is_just_the_next_cycle(): void
    {
        [$shop, $plan] = $this->plan('anchor-skip-ontime.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['renewal_anchor' => MerchantBillingSettings::ANCHOR_SKIP_MISSED])->save();

            // The night before, inside the scheduler's early window.
            $this->travelTo(CarbonImmutable::parse(self::OWED)->subMinutes(30));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            $this->assertSame('2026-02-14 00:00', $plan->fresh()->next_charge_at->format('Y-m-d H:i'));
            $this->assertSame(0, ActivityEvent::query()->where('plan_id', $plan->id)->where('kind', Timeline::KIND_CYCLES_FORGIVEN)->count());
        });
    }

    /**
     * Counted from the scheduled date, not stepped month by month: a plan on the
     * 31st that walked through February one step at a time would land on the
     * 28th and stay there for the rest of its life.
     */
    public function test_skip_missed_does_not_let_a_31st_drift_through_february(): void
    {
        [$shop, $plan] = $this->plan('anchor-skip-31.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['renewal_anchor' => MerchantBillingSettings::ANCHOR_SKIP_MISSED])->save();
            $plan->forceFill(['next_charge_at' => '2026-01-31 00:00:00'])->save();

            // Fixed in April: Feb (28th), Mar (31st) walked over.
            $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00:00'));
            $this->assertTrue(app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING)->isSucceeded());

            $this->assertSame('2026-04-30', $plan->fresh()->next_charge_at->format('Y-m-d'), 'April has no 31st; May would be the 31st again');
        });
    }

    /** A stored value that is not one of the two reads as the default, never as a crash. */
    public function test_an_unknown_stored_anchor_reads_as_the_default(): void
    {
        [$shop] = $this->plan('anchor-garbage.example.com');

        Tenant::run($shop, function (): void {
            $settings = MerchantBillingSettings::current();
            $settings->forceFill(['renewal_anchor' => 'whenever'])->save();

            $this->assertSame(MerchantBillingSettings::ANCHOR_CYCLE, $settings->fresh()->renewalAnchor());
            $this->assertFalse($settings->fresh()->renewsFromChargeDate());
        });
    }

    public function test_the_screen_saves_the_anchor(): void
    {
        [$shop] = $this->plan('anchor-screen.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ManageBillingSettings::class)
                ->assertSet('data.renewal_anchor', MerchantBillingSettings::ANCHOR_CYCLE)
                ->set('data.renewal_anchor', MerchantBillingSettings::ANCHOR_CHARGE_DATE)
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(MerchantBillingSettings::current()->fresh()->renewsFromChargeDate());

            Livewire::test(ManageBillingSettings::class)
                ->set('data.renewal_anchor', MerchantBillingSettings::ANCHOR_SKIP_MISSED)
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(MerchantBillingSettings::current()->fresh()->skipsMissedCycles());
        });
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: InstallmentPlan} an active plan with a card, owed on OWED */
    private function plan(string $domain): array
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
                'next_charge_at' => self::OWED,
            ])->save();

            return $plan->fresh();
        })];
    }
}
