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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE TRANSACTION BOUNDARY around the money, pinned.
 *
 * A gateway call is a network round trip to somebody else's server, with a
 * 30-second timeout. Holding a database transaction — and the plan's row lock —
 * open across it costs a connection and a lock for as long as PayPlus takes to
 * answer, and it means a death mid-flight unwinds the very `pending` ledger row
 * that exists to survive exactly that.
 *
 * UpsellChargeService already learned this the hard way and split into three
 * phases; its own comment records why. This pins the same shape for the main
 * charge path, so it can never quietly grow a transaction around itself again:
 *
 *   A. decide + open the pending ledger row       → committed
 *   B. move the money                             → NO transaction, NO lock
 *   C. record the outcome                         → committed
 *   D. side effects (store order, document, mail) → after everything
 *
 * The pending row surviving a death in phase B is the whole reason it is
 * written before the call.
 */
final class ChargeTransactionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** The transaction nesting level observed AT the moment of the gateway call. */
    public ?int $levelDuringCharge = null;

    public bool $succeed = true;

    /** Throw from inside the gateway, as a dying worker would. */
    public bool $explode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->levelDuringCharge = null;
        $this->succeed = true;
        $this->explode = false;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ChargeTransactionBoundaryTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                // The observation this whole file exists for.
                $this->test->levelDuringCharge = DB::transactionLevel();

                if ($this->test->explode) {
                    throw new \RuntimeException('worker died mid-charge');
                }

                return $this->test->succeed
                    ? GatewayResult::fromResponse([
                        'results' => ['status' => 'success', 'code' => 0],
                        'data' => ['transaction' => ['uid' => 'txn-1', 'approval_number' => 'A']],
                    ])
                    : GatewayResult::fromResponse([
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

    public function test_the_money_moves_outside_every_database_transaction(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        // RefreshDatabase already holds a transaction open around every test, so
        // the question is never "is the level zero" — it is "did charge() add one
        // of its OWN". In production this baseline is 0 and the answer is the
        // same either way.
        $baseline = DB::transactionLevel();

        app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertNotNull($this->levelDuringCharge, 'The gateway was never reached.');
        $this->assertSame(
            $baseline,
            $this->levelDuringCharge,
            'PayPlus was called with a transaction open: the plan row stays locked and a '
            .'connection stays held for the length of a 30-second network timeout.',
        );
    }

    public function test_a_death_during_the_gateway_call_leaves_the_pending_row_behind(): void
    {
        [$shop, $plan] = $this->plan();
        Tenant::set($shop);

        $this->explode = true;

        try {
            app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);
        } catch (\Throwable) {
            // A dying worker is the scenario; what it leaves behind is the point.
        }

        $row = PaymentLedger::where('shop_id', $shop->id)->first();

        $this->assertNotNull(
            $row,
            'The charge may have been taken and NOTHING records it: the pending row was '
            .'rolled back with the transaction that wrapped the gateway call.',
        );
        $this->assertSame(LedgerStatus::PENDING->value, $row->status);
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function plan(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'boundary.myshopify.com',
            'name' => 'Boundary',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-boundary',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'shopify-boundary',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'shopify-boundary',
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
