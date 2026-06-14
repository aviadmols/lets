<?php

namespace Tests\Feature;

use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
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
 * The payment SLOT lifecycle (distinct from the canonical ledger machine): a
 * charge that FAILS then SUCCEEDS on retry must leave the slot in a coherent
 * succeeded state AND produce exactly ONE succeeded ledger row (the same row,
 * transitioned through failed → retry_scheduled → succeeded).
 */
final class PaymentSlotRetryTest extends TestCase
{
    use RefreshDatabase;

    /** Fail the first gateway call, succeed every call after. */
    public bool $firstCallFails = true;

    public int $callCount = 0;

    public function nextResult(): GatewayResult
    {
        $this->callCount++;

        if ($this->firstCallFails && $this->callCount === 1) {
            return GatewayResult::fromResponse([
                'results' => ['status' => 'error', 'code' => 'declined', 'description' => 'Card declined'],
            ]);
        }

        return GatewayResult::fromResponse([
            'results' => ['status' => 'success', 'code' => 0],
            'data' => ['transaction' => ['uid' => 'txn-'.$this->callCount, 'approval_number' => 'A'.$this->callCount]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->callCount = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface {
            public function __construct(private PaymentSlotRetryTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return $this->test->nextResult();
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

    public function test_fail_then_succeed_on_retry_leaves_coherent_slot_and_one_succeeded_ledger(): void
    {
        [$shop, $plan] = $this->makeRecurringPlanWithConsent();
        Tenant::set($shop);

        $cycle = $plan->next_charge_at;
        $orchestrator = app(ChargeOrchestrator::class);

        // First attempt FAILS — slot goes pending → retry_scheduled (not advanced).
        $first = $orchestrator->charge($plan->id, PaymentType::RECURRING);
        $this->assertSame('failed', $first->result);

        $slot = InstallmentPayment::where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(PaymentStatus::RETRY_SCHEDULED, $slot->status, 'Slot is retry_scheduled after a failed attempt.');

        // Re-pin the cycle so the retry derives the SAME recurring idempotency key
        // (a real scheduler retry hits the same cycle before advancement).
        $plan->refresh();
        $plan->forceFill(['next_charge_at' => $cycle])->save();

        // Retry SUCCEEDS — same slot recovers (retry_scheduled → succeeded).
        $second = $orchestrator->charge($plan->id, PaymentType::RECURRING);
        $this->assertTrue($second->isSucceeded());

        $slot->refresh();
        $this->assertSame(PaymentStatus::SUCCEEDED, $slot->status, 'Slot recovers to succeeded on retry.');
        $this->assertSame(2, $this->callCount, 'One failed call + one successful retry.');

        // Exactly ONE succeeded ledger row (the same row transitioned through).
        $this->assertSame(
            1,
            PaymentLedger::where('shop_id', $shop->id)->where('status', LedgerStatus::SUCCEEDED->value)->count(),
            'Exactly one succeeded ledger row.',
        );
        // And only one ledger row total for the cycle key (reused, not duplicated).
        $this->assertSame(1, PaymentLedger::where('shop_id', $shop->id)->count());
        // And only one payment slot total (reused, not a fresh slot per attempt).
        $this->assertSame(1, InstallmentPayment::where('plan_id', $plan->id)->count());
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function makeRecurringPlanWithConsent(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'retry.myshopify.com',
            'name' => 'Retry',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-retry',
                'payplus_customer_uid' => 'cust-retry',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-retry',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-retry',
                'installment_amount' => 49.90,
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
