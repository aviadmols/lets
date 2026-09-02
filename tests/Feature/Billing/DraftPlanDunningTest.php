<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENTERING DUNNING IS LEGAL FROM EVERY STATE A CHARGE CAN START IN.
 *
 * enterDunning() moves a plan to awaiting_payment after a failed attempt, and
 * the plan machine has no draft → awaiting_payment edge: a draft plan must be
 * walked up through awaiting_first_payment first, exactly as ensureActiveThen()
 * does on the success side.
 *
 * Getting this wrong is not a wrong status — it is an IllegalTransitionException
 * thrown from inside the charge, AFTER the gateway has been called. Whatever
 * transaction is open unwinds, and the ledger row that records the attempt goes
 * with it. A declined card would lose only its audit trail; a transport error
 * whose outcome is unknown would lose the only trace that we ever asked.
 *
 * A draft plan reaching a charge is not a normal path — the scheduler filters on
 * chargeable() and the admin button gates on status. But the importer creates
 * plans in draft (SubscriptionImporter), so the state is reachable and the hole
 * is real. This pins the state machine, not the callers that currently avoid it.
 */
final class DraftPlanDunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse([
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

    public function test_the_plan_machine_has_no_direct_draft_to_dunning_edge(): void
    {
        $this->assertNotContains(
            PlanStatus::AWAITING_PAYMENT,
            PlanStatus::allowed()[PlanStatus::DRAFT->value],
            'The guard below exists because this edge is deliberately absent.',
        );
    }

    public function test_a_failed_charge_on_a_draft_plan_records_itself_instead_of_throwing(): void
    {
        [$shop, $plan] = $this->draftPlan();
        Tenant::set($shop);

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(
            PlanStatus::AWAITING_PAYMENT,
            $plan->fresh()->status,
            'A draft plan whose charge failed must walk up to dunning, not throw.',
        );

        $row = PaymentLedger::where('shop_id', $shop->id)->first();
        $this->assertNotNull($row, 'The attempt must be recorded — the gateway was called.');
        $this->assertSame(LedgerStatus::RETRY_SCHEDULED->value, $row->status);
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function draftPlan(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'draftdunning.myshopify.com',
            'name' => 'Draft dunning',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-draft',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-draft',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-draft',
                'installment_amount' => 49.90,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'next_charge_at' => now(),
            ]);
            // The state the importer leaves a row in.
            $plan->forceFill(['status' => PlanStatus::DRAFT->value])->save();

            return $plan;
        });

        return [$shop, $plan];
    }
}
