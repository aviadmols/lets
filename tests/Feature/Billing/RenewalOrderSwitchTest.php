<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\ManageBillingSettings;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Orders\PlatformOrderStrategy;
use App\Services\Orders\PlatformOrderStrategyFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Create an order for each renewal" — the switch a shop selling a membership
 * needs and a shop shipping a box must never lose.
 *
 * The rule being pinned is narrow and load-bearing: turning it off removes ONE
 * thing, the store order. The card is still charged, the ledger still records it,
 * the plan still advances and the customer still gets their invoice — because a
 * merchant who wanted fewer orders did not ask to stop being paid, and the
 * accounting document is the one artefact the tax authority cares about.
 *
 * It is also renewal-only. A deposit's parent order IS the sale, and an
 * instalment plan's order is what gets released when the last payment lands; a
 * switch that silently swallowed those would break the deposit pillar outright.
 */
final class RenewalOrderSwitchTest extends TestCase
{
    use RefreshDatabase;

    /** Contexts the order strategy was asked to materialise, in order. */
    public array $materialised = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->materialised = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$idempotencyKey, 'approval_number' => 'A1']],
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

        // A spy in place of the WooCommerce strategy: this test is about WHETHER
        // the store is asked, never about what the store does with the ask.
        PlatformOrderStrategyFactory::fake(new class($test) implements PlatformOrderStrategy
        {
            public function __construct(private RenewalOrderSwitchTest $test) {}

            public function materialize(InstallmentPlan $plan, ChargeContext $context, bool $isFinal = false): void
            {
                $this->test->materialised[] = $context->value;
            }
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        PlatformOrderStrategyFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    /** The default is the behaviour every shop already has. */
    public function test_a_shop_that_never_touched_the_switch_still_gets_an_order(): void
    {
        [$shop, $plan] = $this->recurringPlan('renewal-default.example.com');

        Tenant::run($shop, function () use ($plan): void {
            $this->assertTrue(MerchantBillingSettings::current()->recurringCreatesOrder());

            $outcome = app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $this->assertTrue($outcome->isSucceeded());
        });

        $this->assertSame([ChargeContext::RECURRING->value], $this->materialised);
    }

    /** Off, the store is not asked at all. */
    public function test_switching_it_off_creates_no_store_order(): void
    {
        [$shop, $plan] = $this->recurringPlan('renewal-off.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['recurring_creates_order' => false])->save();

            $outcome = app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $this->assertTrue($outcome->isSucceeded(), 'the money still moved');
        });

        $this->assertSame([], $this->materialised);
    }

    /**
     * THE RULE THAT MAKES THIS SAFE: it removes an order, not an income.
     *
     * A merchant who wanted fewer orders in their store did not ask to stop being
     * paid, to lose the audit trail, or to stop issuing their customers' receipts.
     */
    public function test_the_money_the_ledger_and_the_schedule_are_untouched(): void
    {
        [$shop, $plan] = $this->recurringPlan('renewal-money.example.com');

        $before = $plan->next_charge_at;

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['recurring_creates_order' => false])->save();
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $ledger = PaymentLedger::query()->where('plan_id', $plan->id)->get();
            $this->assertCount(1, $ledger, 'the charge is still in the ledger');
            $this->assertSame(LedgerStatus::SUCCEEDED->value, (string) $ledger->first()->status);
        });

        $fresh = $plan->fresh();
        $this->assertTrue($fresh->next_charge_at->greaterThan($before), 'the clock still advanced');
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
    }

    /**
     * The absence is EXPLAINED, on the plan's own feed.
     *
     * "Where is October's order?" is asked while looking at the subscription, and
     * the honest answer — "you turned that off" — has to be readable in the place
     * it is asked. Its own kind, never the store-order FAILURE kind: from the
     * store's admin the two look identical, and telling them apart is the whole
     * point of the row.
     */
    public function test_the_skipped_order_is_recorded_on_the_plans_timeline(): void
    {
        [$shop, $plan] = $this->recurringPlan('renewal-timeline.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['recurring_creates_order' => false])->save();
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);

            $this->assertSame(
                1,
                ActivityEvent::query()
                    ->where('plan_id', $plan->id)
                    ->where('kind', Timeline::KIND_STORE_ORDER_SKIPPED)
                    ->count(),
            );

            $this->assertSame(
                0,
                ActivityEvent::query()
                    ->where('plan_id', $plan->id)
                    ->where('kind', Timeline::KIND_STORE_ORDER_FAILED)
                    ->count(),
                'a deliberate skip is never reported as a failure',
            );
        });
    }

    /**
     * RENEWAL-ONLY. A deposit's parent order IS the sale — swallowing it would
     * break the deposit pillar, not tidy it.
     */
    public function test_a_deposit_still_materialises_with_the_switch_off(): void
    {
        [$shop, $plan] = $this->instalmentPlan('renewal-deposit.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()->forceFill(['recurring_creates_order' => false])->save();
            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::DEPOSIT);
        });

        $this->assertSame([ChargeContext::DEPOSIT->value], $this->materialised);
    }

    /** The switch is per shop. One merchant's choice cannot reach another's store. */
    public function test_the_switch_is_per_shop(): void
    {
        [$off, $offPlan] = $this->recurringPlan('renewal-per-shop-off.example.com');
        [$on, $onPlan] = $this->recurringPlan('renewal-per-shop-on.example.com');

        Tenant::run($off, static function (): void {
            MerchantBillingSettings::current()->forceFill(['recurring_creates_order' => false])->save();
        });

        Tenant::run($off, fn () => app(ChargeOrchestrator::class)->charge((int) $offPlan->id, PaymentType::RECURRING));
        Tenant::run($on, fn () => app(ChargeOrchestrator::class)->charge((int) $onPlan->id, PaymentType::RECURRING));

        $this->assertSame(
            [ChargeContext::RECURRING->value],
            $this->materialised,
            'only the shop that left it on was asked for an order',
        );
    }

    /** The screen writes it, and the engine reads what the screen wrote. */
    public function test_the_settings_screen_saves_the_switch(): void
    {
        $shop = $this->shop('renewal-screen.example.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        Livewire::test(ManageBillingSettings::class)
            ->assertSet('data.recurring_creates_order', true)
            ->set('data.recurring_creates_order', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(MerchantBillingSettings::current()->fresh()->recurringCreatesOrder());
    }

    // === Fixtures ===

    private function shop(string $domain): Shop
    {
        // WooCommerce: the orchestrator routes a Woo shop through
        // PlatformOrderStrategyFactory, which is where the spy is installed.
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function recurringPlan(string $domain): array
    {
        $shop = $this->shop($domain);

        return [$shop, Tenant::run($shop, static function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'cust-1',
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'cust-1',
                'customer_name' => 'Dana Levi',
                'installment_amount' => 39.00,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'next_charge_at' => now(),
                'meta' => ['item_title' => 'Club membership'],
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        })];
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function instalmentPlan(string $domain): array
    {
        $shop = $this->shop($domain);

        return [$shop, Tenant::run($shop, static function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-2',
                'payplus_customer_uid' => 'cust-2',
                'card_brand' => 'visa',
                'card_last_four' => '1111',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => 'cust-2',
                'consent_context' => CustomerConsent::CONTEXT_INSTALLMENTS,
                'accepted_at' => now(),
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'cust-2',
                'customer_name' => 'Yossi Cohen',
                'total_amount' => 300.00,
                'total_charged' => 0,
                'installment_amount' => 100.00,
                'currency' => 'ILS',
                'next_charge_at' => now(),
                'meta' => ['item_title' => 'Sofa'],
            ]);
            $plan->forceFill(['status' => PlanStatus::AWAITING_FIRST_PAYMENT->value])->save();

            return $plan;
        })];
    }
}
