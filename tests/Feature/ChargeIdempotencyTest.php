<?php

namespace Tests\Feature;

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
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The double-charge wall. A duplicated charge for the same deterministic key
 * must yield exactly ONE succeeded ledger row and exactly ONE PayPlus call —
 * the idempotent short-circuit on the succeeded ledger row.
 */
final class ChargeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public int $payplusCallCount = 0;

    /** Called by the fake gateway on every charge; returns the new running count. */
    public function recordPayplusCall(): int
    {
        return ++$this->payplusCallCount;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->payplusCallCount = 0;
        $test = $this;

        // Fake gateway: always succeeds, counts calls. No real HTTP.
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface {
            public function __construct(private ChargeIdempotencyTest $test) {}

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

    public function test_duplicate_charge_key_yields_one_succeeded_ledger_row_and_one_payplus_call(): void
    {
        [$shop, $plan] = $this->makeRecurringPlanWithToken();

        Tenant::set($shop);
        $orchestrator = app(ChargeOrchestrator::class);

        // Pin next_charge_at so both runs derive the SAME recurring cycle key.
        $plan->forceFill(['next_charge_at' => now()])->save();
        $cycleKey = $plan->next_charge_at;

        $first = $orchestrator->charge($plan->id, PaymentType::RECURRING);

        // Re-pin the cycle so the second run produces the same idempotency key
        // (simulating a duplicate trigger for the same cycle before advancement).
        $plan->refresh();
        $plan->forceFill(['next_charge_at' => $cycleKey])->save();

        $second = $orchestrator->charge($plan->id, PaymentType::RECURRING);

        $this->assertTrue($first->isSucceeded());
        $this->assertSame(ChargeOutcome::RESULT_SKIPPED, $second->result);
        $this->assertSame('already_succeeded', $second->reason);

        $this->assertSame(1, $this->payplusCallCount, 'PayPlus must be called exactly once.');

        $succeeded = PaymentLedger::where('shop_id', $shop->id)
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->count();
        $this->assertSame(1, $succeeded, 'Exactly one succeeded ledger row.');

        // And no charge ever ran without a ledger row.
        $this->assertSame(1, PaymentLedger::where('shop_id', $shop->id)->count());
    }

    public function test_no_charge_without_a_ledger_row(): void
    {
        [$shop, $plan] = $this->makeRecurringPlanWithToken();
        Tenant::set($shop);

        $plan->forceFill(['next_charge_at' => now()])->save();
        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        // The single PayPlus call has a matching ledger row.
        $this->assertSame($this->payplusCallCount, PaymentLedger::where('shop_id', $shop->id)->count());
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function makeRecurringPlanWithToken(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'idem.myshopify.com',
            'name' => 'Idem',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        // Secrets are NOT mass-assignable (not in $fillable) — set directly.
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            // Saved-token recurring charges require a stored consent row.
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
            // status is guarded (FIX #5) — set the initial state via forceFill.
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        })];
    }
}
