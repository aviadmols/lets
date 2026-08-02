<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
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
 * The intro-discount window through the REAL charge pipeline: "20% off the first
 * 2 charges" ⇒ the checkout (seq 0) + one recurring cycle bill the discounted
 * amount, the next cycle bills the regular price, and the step-up is recorded
 * on the Timeline EXACTLY ONCE (the emit-once meta flag).
 */
final class IntroWindowChargeTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS — ₪100 regular, ₪80 for the first 2 charges ===
    private const STEADY = 80.00;

    private const REGULAR = 100.00;

    private const WINDOW = 2;

    /** @var list<float> the amounts PayPlus was actually asked to charge */
    public array $chargedAmounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->chargedAmounts = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private IntroWindowChargeTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->chargedAmounts[] = $amount;
                $n = count($this->test->chargedAmounts);

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

    public function test_the_price_steps_up_after_the_window_with_one_timeline_event(): void
    {
        [$shop, $plan] = $this->activatedWindowedPlan();
        Tenant::set($shop);
        $orchestrator = app(ChargeOrchestrator::class);

        // Charge #2 — the last discounted cycle (checkout was #1).
        $plan->forceFill(['next_charge_at' => now()])->save();
        $this->assertTrue($orchestrator->charge($plan->id, PaymentType::RECURRING)->isSucceeded());
        $this->assertSame(self::STEADY, end($this->chargedAmounts));

        // Charge #3 — first past the window: the regular price + the step-up event.
        $this->assertTrue($orchestrator->charge($plan->fresh()->id, PaymentType::RECURRING)->isSucceeded());
        $this->assertSame(self::REGULAR, end($this->chargedAmounts));

        // Charge #4 — still regular, and NO second step-up event.
        $this->assertTrue($orchestrator->charge($plan->fresh()->id, PaymentType::RECURRING)->isSucceeded());
        $this->assertSame(self::REGULAR, end($this->chargedAmounts));

        $this->assertSame(1, ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', Timeline::KIND_PRICE_STEPPED_UP)
            ->count(), 'The step-up must be recorded exactly once.');
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: InstallmentPlan} an ACTIVE windowed plan whose checkout already succeeded */
    private function activatedWindowedPlan(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'intro.myshopify.com',
            'name' => 'Intro',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, function (): InstallmentPlan {
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
                'charge_context' => 'recurring',
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-cust-1',
                'installment_amount' => self::STEADY,
                'regular_amount' => self::REGULAR,
                'discount_cycles' => self::WINDOW,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'public_id' => 'PLAN-'.uniqid(),
                'next_charge_at' => now(),
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            // The checkout payment: the succeeded seq-0 slot (charge #1).
            $checkout = InstallmentPayment::create([
                'plan_id' => $plan->getKey(),
                'sequence' => 0,
                'payment_type' => PaymentType::DEPOSIT->value,
                'amount' => self::STEADY,
                'currency' => 'ILS',
            ]);
            $checkout->forceFill(['status' => PaymentStatus::SUCCEEDED->value])->save();

            return $plan;
        })];
    }
}
