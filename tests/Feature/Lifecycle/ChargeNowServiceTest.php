<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\ChargeNowService;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave-1b "Charge now" (admin out-of-schedule trigger). It is a thin wrapper over the
 * ChargeOrchestrator, so it inherits every money-safety law: a real saved-token charge
 * with consent, idempotent on a double-click, and FAIL-CLOSED without consent. Same
 * faked-gateway pattern as ConsentGateTest.
 */
final class ChargeNowServiceTest extends TestCase
{
    use RefreshDatabase;

    public int $payplusCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payplusCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ChargeNowServiceTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->payplusCalls++;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$this->test->payplusCalls]],
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

    public function test_charge_now_charges_the_saved_token_once(): void
    {
        [$shop, $plan] = $this->makePlan(withConsent: true);
        Tenant::set($shop);

        $outcome = app(ChargeNowService::class)->chargeNow($plan);

        $this->assertTrue($outcome->isSucceeded());
        $this->assertStringStartsWith('recurring:', $outcome->idempotencyKey); // RECURRING type chosen
        $this->assertSame(1, $this->payplusCalls);
        $this->assertSame(1, PaymentLedger::where('shop_id', $shop->id)
            ->where('status', LedgerStatus::SUCCEEDED->value)->count());
    }

    public function test_charge_now_fails_closed_without_consent(): void
    {
        [$shop, $plan] = $this->makePlan(withConsent: false);
        Tenant::set($shop);

        $outcome = app(ChargeNowService::class)->chargeNow($plan);

        $this->assertSame('skipped', $outcome->result);
        $this->assertSame('no_consent', $outcome->reason);
        $this->assertSame(0, $this->payplusCalls);
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function makePlan(bool $withConsent): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'cn.myshopify.com',
            'name' => 'CN',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () use ($withConsent) {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            if ($withConsent) {
                CustomerConsent::create([
                    'shopify_customer_id' => 'sc-1',
                    'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                    'accepted_at' => now(),
                ]);
            }

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'sc-1',
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
