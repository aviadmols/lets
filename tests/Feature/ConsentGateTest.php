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
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money-safety law (CLAUDE.md): a saved-token charge requires a stored
 * customer_consents row. With no consent the orchestrator must FAIL CLOSED —
 * NO gateway call, NO succeeded ledger row, a `consent_missing` Timeline event —
 * and proceed normally once consent exists.
 */
final class ConsentGateTest extends TestCase
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

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface {
            public function __construct(private ConsentGateTest $test) {}

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

    public function test_charge_is_skipped_when_consent_is_missing(): void
    {
        [$shop, $plan] = $this->makeRecurringPlan(withConsent: false);
        Tenant::set($shop);

        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertSame(ChargeOutcome::RESULT_SKIPPED, $outcome->result);
        $this->assertSame('no_consent', $outcome->reason);

        // Fail closed: no gateway call, and NO succeeded ledger row exists.
        $this->assertSame(0, $this->payplusCallCount, 'No PayPlus call without consent.');
        $this->assertSame(
            0,
            PaymentLedger::where('shop_id', $shop->id)->where('status', LedgerStatus::SUCCEEDED->value)->count(),
            'No succeeded ledger row without consent.',
        );

        // The plan is left for admin attention via a consent_missing Timeline event.
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'kind' => Timeline::KIND_CONSENT_MISSING,
        ]);
    }

    public function test_charge_proceeds_when_consent_is_present(): void
    {
        [$shop, $plan] = $this->makeRecurringPlan(withConsent: true);
        Tenant::set($shop);

        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertTrue($outcome->isSucceeded());
        $this->assertSame(1, $this->payplusCallCount, 'Exactly one PayPlus call with consent.');
        $this->assertSame(
            1,
            PaymentLedger::where('shop_id', $shop->id)->where('status', LedgerStatus::SUCCEEDED->value)->count(),
            'One succeeded ledger row with consent.',
        );
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function makeRecurringPlan(bool $withConsent): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'consent.myshopify.com',
            'name' => 'Consent',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () use ($withConsent) {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-9',
                'payplus_customer_uid' => 'cust-9',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            if ($withConsent) {
                CustomerConsent::create([
                    'shopify_customer_id' => 'shopify-cust-9',
                    'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                    'accepted_at' => now(),
                ]);
            }

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-cust-9',
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
