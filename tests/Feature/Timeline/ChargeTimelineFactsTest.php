<?php

namespace Tests\Feature\Timeline;

use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use App\Support\Ui\EventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The charge path WRITES what its Timeline rows need to say: which charge, how
 * much, which card, the approval number and why it ran — and the rows read in
 * Hebrew without the transaction uid or the idempotency key.
 */
final class ChargeTimelineFactsTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    public const UID = 'b7e0c9aa-1111-2222-3333-444455556666';

    public bool $decline = false;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ChargeTimelineFactsTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse($this->test->decline
                    ? ['results' => ['status' => 'error', 'code' => 1, 'description' => 'כרטיס חסום']]
                    : [
                        'results' => ['status' => 'success', 'code' => 0],
                        'data' => ['transaction' => ['uid' => ChargeTimelineFactsTest::UID, 'approval_number' => '0654321']],
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
            'woocommerce_domain' => 'facts.example.com',
            'name' => 'Facts',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $this->shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't', 'payment_page_uid' => 'p'];
        $this->shop->save();
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_scheduled_renewal_records_and_renders_what_was_charged(): void
    {
        $plan = $this->plan();

        $outcome = Tenant::run($this->shop, fn (): ChargeOutcome => app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING));
        $this->assertSame(ChargeOutcome::RESULT_SUCCEEDED, $outcome->result);

        [$started, $succeeded] = Tenant::run($this->shop, fn (): array => [
            ActivityEvent::query()->where('plan_id', $plan->id)->where('kind', 'charge_attempt_started')->sole(),
            ActivityEvent::query()->where('plan_id', $plan->id)->where('kind', 'charge_succeeded')->sole(),
        ]);

        // The attempt row now carries what it opens.
        $this->assertSame('auto_renewal', $started->details['trigger']);
        $this->assertEquals(39.0, $started->details['amount']);
        $this->assertSame('4242', $started->details['card_last_four']);
        $this->assertArrayHasKey('charge_number', $started->details);

        $this->assertSame('0654321', $succeeded->details['approval_number']);
        $this->assertSame('auto_renewal', $succeeded->details['trigger']);

        app()->setLocale('he');
        $summary = (string) EventPresenter::summarize($succeeded);
        $this->assertStringStartsWith('חידוש אוטומטי', $summary);
        $this->assertStringContainsString('כרטיס: visa •••• 4242', $summary);
        $this->assertStringContainsString('מספר אישור: 0654321', $summary);
        $this->assertStringNotContainsString(self::UID, $summary);
        $this->assertStringNotContainsString('recurring:', $summary);

        $attempt = (string) EventPresenter::summarize($started);
        $this->assertStringStartsWith('חידוש אוטומטי', $attempt);
        $this->assertStringContainsString('39', $attempt);
        $this->assertNotSame(__('timeline.kind.generic'), EventPresenter::label($started));

        // The payment row's own move reads the LEDGER words, as a payment.
        $move = Tenant::run($this->shop, fn (): ActivityEvent => ActivityEvent::query()
            ->where('plan_id', $plan->id)->where('kind', 'status_changed')
            ->whereNotNull('payment_id')->latest('id')->first());
        $this->assertSame('סטטוס התשלום השתנה', EventPresenter::label($move));
        $this->assertStringContainsString('ממתין ← הצליח', (string) EventPresenter::summarize($move));
    }

    public function test_a_decline_records_the_card_amount_and_reason(): void
    {
        $this->decline = true;
        $plan = $this->plan();

        Tenant::run($this->shop, fn (): ChargeOutcome => app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING));

        $row = Tenant::run($this->shop, fn (): ActivityEvent => ActivityEvent::query()
            ->where('plan_id', $plan->id)
            ->whereIn('kind', ['charge_retry_scheduled', 'charge_failed'])
            ->latest('id')->first());

        $this->assertSame('4242', $row->details['card_last_four']);
        $this->assertEquals(39.0, $row->details['amount']);
        // The FIRST attempt is not a retry.
        $this->assertSame('auto_renewal', $row->details['trigger']);

        app()->setLocale('he');
        $this->assertStringContainsString('סיבה: כרטיס חסום', (string) EventPresenter::summarize($row));
    }

    private function plan(): InstallmentPlan
    {
        return Tenant::run($this->shop, function (): InstallmentPlan {
            $customer = 'cust-'.uniqid();

            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => $customer,
                'payplus_card_token_uid' => 'tok-'.$customer,
                'payplus_customer_uid' => 'pp-'.$customer,
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
                'customer_email' => $customer.'@example.com',
                'total_amount' => 39,
                'total_charged' => 0,
                'installment_amount' => 39,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => now()->subMinute()->startOfMinute(),
            ])->save();

            return $plan->fresh();
        });
    }
}
