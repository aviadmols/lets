<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ConsentCeiling;
use App\Domain\Lifecycle\SubscriptionEditService;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Consent covers WHAT was agreed, for THIS plan: a charge above the consented
 * amount or on a tighter cadence is refused (logged + Timeline, no ledger row,
 * no gateway call) until the customer consents again or the merchant approves.
 */
final class ConsentCeilingTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER = 'shopify-cust-ceiling';

    private const PRICE = 100.00;

    public int $calls = 0;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ConsentCeilingTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->calls;

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

        $this->shop = Shop::create([
            'shopify_domain' => 'ceiling.myshopify.com',
            'name' => 'Ceiling',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $this->shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $this->shop->save();
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_charge_within_the_consented_amount_runs(): void
    {
        $plan = $this->plan();
        $this->consentFor($plan);

        $this->assertTrue($this->charge($plan)->isSucceeded());
        $this->assertSame(1, $this->calls);
    }

    public function test_a_merchant_price_rise_above_consent_is_refused_and_shown(): void
    {
        $plan = $this->plan();
        $this->consentFor($plan);
        $plan->forceFill(['installment_amount' => 150.00])->save();

        $outcome = $this->charge($plan);

        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $outcome->reason);
        $this->assertSame(0, $this->calls, 'No gateway call.');
        $this->assertSame(0, PaymentLedger::query()->count(), 'No ledger row.');
        $this->assertSame(0, $plan->payments()->count(), 'No slot frozen at the refused price.');
        $this->assertDatabaseHas('activity_events', ['plan_id' => $plan->id, 'kind' => Timeline::KIND_CHARGE_ABOVE_CONSENT]);

        // The scheduler re-asks: the Timeline says it once, not every tick.
        $this->charge($plan->fresh());
        $this->assertSame(1, ActivityEvent::query()->where('plan_id', $plan->id)->where('kind', Timeline::KIND_CHARGE_ABOVE_CONSENT)->count());
    }

    public function test_a_merchant_approval_is_logged_and_lets_the_charge_run(): void
    {
        $plan = $this->plan();
        $consent = $this->consentFor($plan);
        $plan->forceFill(['installment_amount' => 150.00])->save();

        app(ConsentCeiling::class)->approveAboveConsent($plan->fresh(), $consent, 150.00, 'Customer agreed by phone');

        $this->assertDatabaseHas('customer_consents', [
            'plan_id' => $plan->id,
            'ceiling_source' => ConsentCeiling::SOURCE_MERCHANT_OVERRIDE,
            'approval_reason' => 'Customer agreed by phone',
        ]);
        $this->assertDatabaseHas('activity_events', ['plan_id' => $plan->id, 'kind' => Timeline::KIND_CONSENT_OVERRIDE_APPROVED]);
        $this->assertTrue($this->charge($plan->fresh())->isSucceeded());
    }

    public function test_a_tighter_cadence_is_refused(): void
    {
        $plan = $this->plan(frequency: 'yearly');
        $this->consentFor($plan);
        $plan->forceFill(['billing_frequency' => 'monthly'])->save();

        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $this->charge($plan)->reason);
        $this->assertSame(0, $this->calls);
    }

    public function test_a_consent_for_another_plan_does_not_transfer(): void
    {
        $other = $this->plan();
        $this->consentFor($other);
        $plan = $this->plan();

        $this->assertSame('no_consent', $this->charge($plan)->reason);
        $this->assertSame(0, $this->calls);
    }

    public function test_a_legacy_customer_wide_consent_is_bound_at_todays_terms(): void
    {
        CustomerConsent::create([
            'shopify_customer_id' => self::CUSTOMER,
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now(),
        ]);
        $plan = $this->plan();

        $this->assertTrue($this->charge($plan)->isSucceeded());
        $this->assertDatabaseHas('customer_consents', [
            'plan_id' => $plan->id,
            'ceiling_source' => ConsentCeiling::SOURCE_BASELINE,
            'consented_amount' => self::PRICE,
        ]);
    }

    public function test_a_next_order_the_customer_set_themselves_may_exceed_the_ceiling_once(): void
    {
        $plan = $this->plan();
        $this->consentFor($plan);

        $meta = (array) $plan->meta;
        $meta[InstallmentPlan::META_NEXT_ORDER] = ['line_items' => [['product_id' => 1, 'name' => 'Box', 'quantity' => 3, 'unit_price' => 100.00]], 'amount' => 300.00, 'currency' => 'ILS', 'set_by' => ActivityEvent::ACTOR_CUSTOMER];
        $plan->forceFill(['meta' => $meta])->save();

        $this->assertTrue($this->charge($plan->fresh())->isSucceeded());

        // The same amount set by the MERCHANT is refused.
        $second = $this->plan();
        $this->consentFor($second);
        $meta[InstallmentPlan::META_NEXT_ORDER]['set_by'] = 'admin:1';
        $second->forceFill(['meta' => $meta])->save();

        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $this->charge($second->fresh())->reason);
    }

    public function test_a_legacy_override_pending_when_the_ceiling_is_baselined_charges(): void
    {
        // A pre-ceiling consent row (no consented_amount) and a next order raised
        // before deploy — stamped set_by=system, as the storefront used to write it.
        $plan = $this->plan();
        CustomerConsent::create([
            'plan_id' => $plan->id,
            'shopify_customer_id' => self::CUSTOMER,
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now()->subMonth(),
        ]);
        $this->queueOverride($plan, 300.00, ActivityEvent::ACTOR_SYSTEM, now()->subDay()->toIso8601String());

        $this->assertTrue($this->charge($plan->fresh())->isSucceeded());
        $this->assertDatabaseHas('customer_consents', [
            'plan_id' => $plan->id,
            'ceiling_source' => ConsentCeiling::SOURCE_BASELINE,
            'consented_amount' => 300.00,
        ]);
    }

    public function test_an_override_set_before_the_ceiling_row_is_covered(): void
    {
        $plan = $this->plan();
        $this->queueOverride($plan, 300.00, ActivityEvent::ACTOR_SYSTEM, now()->subDay()->toIso8601String());
        $this->consentFor($plan); // ceiling at the plan price, drawn AFTER the override was set

        $this->assertTrue($this->charge($plan->fresh())->isSucceeded());
    }

    public function test_a_new_merchant_raise_after_the_ceiling_is_refused_until_approved(): void
    {
        $plan = $this->plan();
        $consent = $this->consentFor($plan);

        $this->travel(5)->minutes();
        $this->queueOverride($plan, 300.00, 'admin:1', now()->toIso8601String());

        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $this->charge($plan->fresh())->reason);
        $this->assertSame(0, $this->calls);

        // A system-stamped override written after the ceiling is not "legacy" either.
        $this->queueOverride($plan, 300.00, ActivityEvent::ACTOR_SYSTEM, now()->toIso8601String());
        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $this->charge($plan->fresh())->reason);

        // …and one with no set_at at all fails closed.
        $this->queueOverride($plan, 300.00, ActivityEvent::ACTOR_SYSTEM, null);
        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $this->charge($plan->fresh())->reason);

        app(ConsentCeiling::class)->approveAboveConsent($plan->fresh(), $consent, 300.00, 'Customer asked by phone');
        $this->assertTrue($this->charge($plan->fresh())->isSucceeded());
    }

    public function test_the_backfill_draws_ceilings_at_deploy_and_is_idempotent(): void
    {
        CustomerConsent::create([
            'shopify_customer_id' => self::CUSTOMER,
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now()->subYear(),
        ]);
        $plan = $this->plan();
        $this->queueOverride($plan, 180.00, ActivityEvent::ACTOR_SYSTEM, now()->subDay()->toIso8601String());

        $migration = require base_path('database/migrations/2026_09_29_000006_backfill_consent_ceilings_for_live_plans.php');
        Tenant::clear(); // a migration runs with no tenant bound
        $migration->up();
        $migration->up();
        Tenant::set($this->shop);

        $rows = CustomerConsent::query()->where('plan_id', $plan->id)->get();
        $this->assertCount(1, $rows, 'Bound once, not once per run.');
        $this->assertSame(ConsentCeiling::SOURCE_BASELINE, $rows[0]->ceiling_source);
        $this->assertEqualsWithDelta(180.00, (float) $rows[0]->consented_amount, 0.001);

        // A merchant price edit AFTER deploy is not blessed by the next charge.
        $plan->fresh()->clearNextOrderOverride();
        $plan->forceFill(['installment_amount' => 250.00])->save();
        $this->assertSame(ChargeOrchestrator::SKIP_ABOVE_CONSENT, $this->charge($plan->fresh())->reason);
        $this->assertSame(0, $this->calls);
    }

    public function test_one_broken_plan_does_not_abort_the_backfill(): void
    {
        CustomerConsent::create([
            'shopify_customer_id' => self::CUSTOMER,
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now()->subYear(),
        ]);
        $good = $this->plan();
        $broken = $this->plan();
        $alsoGood = $this->plan();
        // A value the enum cast refuses — hydration-time trouble, as a bad import leaves.
        DB::table('installment_plans')->where('id', $broken->id)->update(['plan_kind' => 'not-a-kind']);

        Log::spy();
        $migration = require base_path('database/migrations/2026_09_29_000006_backfill_consent_ceilings_for_live_plans.php');
        Tenant::clear();
        $migration->up();
        Tenant::set($this->shop);

        foreach ([$good, $alsoGood] as $plan) {
            $this->assertDatabaseHas('customer_consents', [
                'plan_id' => $plan->id,
                'ceiling_source' => ConsentCeiling::SOURCE_BASELINE,
            ]);
        }
        $this->assertDatabaseMissing('customer_consents', ['plan_id' => $broken->id]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'migration.consent_ceiling_backfill_skipped'
                && $context['plan_id'] === $broken->id
                && $context['shop_id'] === $this->shop->id
                && $context['exception'] === \ValueError::class)
            ->once();
    }

    public function test_the_edit_service_attributes_a_customer_edit_to_the_customer(): void
    {
        $this->assertTrue(method_exists(SubscriptionEditService::class, 'editNextCharge'));
        $params = (new \ReflectionMethod(SubscriptionEditService::class, 'editNextCharge'))->getParameters();
        $this->assertSame('actor', $params[2]->getName());
    }

    private function charge(InstallmentPlan $plan): \App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome
    {
        return app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);
    }

    private function queueOverride(InstallmentPlan $plan, float $amount, string $setBy, ?string $setAt): void
    {
        $plan = $plan->fresh();
        $meta = (array) $plan->meta;
        $meta[InstallmentPlan::META_NEXT_ORDER] = array_filter([
            'line_items' => [['product_id' => 1, 'name' => 'Box', 'quantity' => 1, 'unit_price' => $amount]],
            'amount' => $amount,
            'currency' => 'ILS',
            'set_by' => $setBy,
            'set_at' => $setAt,
        ], static fn ($v): bool => $v !== null);
        $plan->forceFill(['meta' => $meta])->save();
    }

    private function consentFor(InstallmentPlan $plan): CustomerConsent
    {
        return CustomerConsent::create([
            'plan_id' => $plan->id,
            'shopify_customer_id' => self::CUSTOMER,
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now(),
        ] + ConsentCeiling::termsFor($plan));
    }

    private function plan(string $frequency = 'monthly'): InstallmentPlan
    {
        $method = InstallmentPaymentMethod::create([
            'payplus_card_token_uid' => 'tok-'.uniqid(),
            'payplus_customer_uid' => 'cust-c',
            'card_last_four' => '4242',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);

        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'payment_method_id' => $method->id,
            'shopify_customer_id' => self::CUSTOMER,
            'installment_amount' => self::PRICE,
            'billing_frequency' => $frequency,
            'interval_count' => 1,
            'currency' => 'ILS',
            'next_charge_at' => now(),
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan->fresh();
    }
}
