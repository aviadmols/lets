<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
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
 * The card's label, correcting itself.
 *
 * We store the expiry once — at vaulting, or copied from a migration file — and
 * never again, while the TOKEN keeps working straight through a bank's renewal
 * that reissues the same card with a new date. A store therefore arrives with
 * hundreds of cards our data calls expired and PayPlus charges without blinking,
 * which is exactly what a merchant found. PayPlus describes the card it charged
 * in every response; these pin down that we listen, and that we listen carefully.
 */
final class CardLabelRefreshTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $cardInformation = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private CardLabelRefreshTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => [
                        'transaction' => ['uid' => 'txn-1', 'approval_number' => 'A1'],
                        'card_information' => $this->test->cardInformation(),
                    ],
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

    /** @return array<string, mixed> */
    public function cardInformation(): array
    {
        return $this->cardInformation;
    }

    public function test_a_successful_charge_corrects_a_stale_expiry(): void
    {
        // The migration file said 2019. The bank renewed the card years ago.
        $this->cardInformation = ['expiry_month' => 11, 'expiry_year' => 2030, 'four_digits' => '3428'];

        [$shop, $plan, $method] = $this->planWithCard(expMonth: 4, expYear: 2019);

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $method->refresh();

        $this->assertSame(11, (int) $method->exp_month);
        $this->assertSame(2030, (int) $method->exp_year);
        $this->assertSame('3428', $method->card_last_four);
    }

    /** PayPlus sends "30" as often as "2030". Stored bare it would read as year 30. */
    public function test_a_two_digit_year_is_read_as_this_century(): void
    {
        $this->cardInformation = ['expiry_month' => 9, 'expiry_year' => 29];

        [$shop, $plan, $method] = $this->planWithCard(expMonth: 4, expYear: 2019);

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $this->assertSame(2029, (int) $method->refresh()->exp_year);
    }

    /** A field the response did not carry must not blank a good stored value. */
    public function test_a_missing_field_never_overwrites_what_we_hold(): void
    {
        $this->cardInformation = ['expiry_month' => 6, 'expiry_year' => 2031];

        [$shop, $plan, $method] = $this->planWithCard(expMonth: 4, expYear: 2019);

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $method->refresh();

        $this->assertSame(2031, (int) $method->exp_year);
        $this->assertSame('4242', $method->card_last_four, 'the four digits we already had survive');
    }

    /** A label is not a credential: the token is never touched. */
    public function test_the_token_is_never_rewritten(): void
    {
        $this->cardInformation = ['expiry_month' => 1, 'expiry_year' => 2032, 'token' => 'a-different-token'];

        [$shop, $plan, $method] = $this->planWithCard(expMonth: 4, expYear: 2019);

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $this->assertSame('tok-1', $method->refresh()->payplus_card_token_uid);
    }

    /** Nothing changed means nothing written — a monthly cycle is thousands of charges. */
    public function test_an_unchanged_label_is_not_rewritten(): void
    {
        [$shop, $plan, $method] = $this->planWithCard(expMonth: 4, expYear: 2019);

        $this->cardInformation = ['expiry_month' => 4, 'expiry_year' => 2019, 'four_digits' => '4242'];

        $before = $method->updated_at;

        Tenant::run($shop, function () use ($plan): void {
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $this->assertEquals($before, $method->refresh()->updated_at);
    }

    /** @return array{0: Shop, 1: InstallmentPlan, 2: InstallmentPaymentMethod} */
    private function planWithCard(int $expMonth, int $expYear): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'card-label-'.uniqid('', true).'.myshopify.com',
            'name' => 'Card label',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        [$plan, $method] = Tenant::run($shop, static function () use ($expMonth, $expYear): array {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

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
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return [$plan, $method];
        });

        return [$shop, $plan, $method];
    }
}
