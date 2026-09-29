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
