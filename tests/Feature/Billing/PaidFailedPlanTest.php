<?php

namespace Tests\Feature\Billing;

use App\Domain\Lifecycle\ChargeNowService;
use App\Filament\Pages\PaymentRecovery;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A SUBSCRIPTION THAT IS PAID IS NOT `failed`.
 *
 * Plan 995, 16/09. A member the CSV importer had filed as `failed` (source: past_due)
 * saved a new card; an admin pressed "charge now"; ₪39 came in and a document went out.
 * The plan stayed `failed`. The success path returned AWAITING_PAYMENT to active and
 * never looked at FAILED, so:
 *
 *   - the Failed charges screen kept listing a paying subscriber as stopped, and
 *   - `failed` is not in PlanStatus::chargeable(), so the scheduler would have skipped
 *     his October cycle — a customer who fixed his card and paid, lapsing a month later.
 *
 * The last test is the fence: a pause the CUSTOMER asked for is not lifted by money.
 */
final class PaidFailedPlanTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER = 'legacy-past-due';

    protected function setUp(): void
    {
        parent::setUp();

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.uniqid(), 'approval_number' => 'A1']],
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

    public function test_charging_an_imported_past_due_plan_makes_it_active_and_schedules_its_next_cycle(): void
    {
        [$shop, $plan] = $this->plan(PlanStatus::FAILED);

        $plan = Tenant::run($shop, function () use ($plan): InstallmentPlan {
            $this->assertTrue(app(ChargeNowService::class)->chargeNow($plan)->isSucceeded());

            return $plan->fresh();
        });

        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertTrue($plan->next_charge_at->isFuture());
        $this->assertContains($plan->status->value, PlanStatus::chargeable(), 'the scheduler must be able to bill it again');

        Tenant::run($shop, function () use ($plan): void {
            $this->assertFalse(
                PaymentRecovery::scopeFor(PaymentRecovery::TAB_STOPPED, InstallmentPlan::query())->whereKey($plan->getKey())->exists(),
                'a paid subscriber is not listed as stopped',
            );
        });
    }

    public function test_the_scheduler_bills_its_next_cycle(): void
    {
        [$shop, $plan] = $this->plan(PlanStatus::FAILED);

        $next = Tenant::run($shop, function () use ($plan) {
            app(ChargeNowService::class)->chargeNow($plan);

            return $plan->fresh()->next_charge_at;
        });

        // A month later: the cycle 995 would have silently skipped.
        $this->travelTo($next->copy()->addMinute());

        Queue::fake();
        $this->artisan('payplus:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(ChargeJob::class, static fn (ChargeJob $job): bool => $job->planId === (int) $plan->getKey());
    }

    public function test_a_failed_plan_collection_had_held_is_released_as_well(): void
    {
        [$shop, $plan] = $this->plan(PlanStatus::FAILED, heldSince: now()->subWeek());

        $plan = Tenant::run($shop, function () use ($plan): InstallmentPlan {
            app(ChargeNowService::class)->chargeNow($plan);

            return $plan->fresh();
        });

        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertNull($plan->payment_failed_at);
    }

    public function test_the_final_slice_of_a_failed_installments_plan_completes_it(): void
    {
        // failed → completed is not an edge. Before, this threw AFTER the gateway had
        // taken the money, and the rollback erased the record of it.
        [$shop, $plan] = $this->plan(PlanStatus::FAILED, kind: PlanKind::INSTALLMENTS);

        $plan = Tenant::run($shop, function () use ($plan): InstallmentPlan {
            $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::INSTALLMENT);
            $this->assertTrue($outcome->isSucceeded());

            return $plan->fresh();
        });

        $this->assertSame(PlanStatus::COMPLETED, $plan->status);
    }

    public function test_a_pause_the_customer_asked_for_is_not_lifted_by_a_payment(): void
    {
        [$shop, $plan] = $this->plan(PlanStatus::PAUSED, nextChargeAt: now()->subDay());

        $plan = Tenant::run($shop, function () use ($plan): InstallmentPlan {
            app(ChargeNowService::class)->chargeNow($plan);

            return $plan->fresh();
        });

        $this->assertSame(PlanStatus::PAUSED, $plan->status);
    }

    // === Fixtures ===

    /**
     * The shape the importer leaves: no clock, no hold stamp, no slots — unless asked.
     *
     * @return array{0: Shop, 1: InstallmentPlan}
     */
    private function plan(
        PlanStatus $status,
        PlanKind $kind = PlanKind::RECURRING,
        ?\DateTimeInterface $heldSince = null,
        ?\DateTimeInterface $nextChargeAt = null,
    ): array {
        $shop = Shop::create([
            'woocommerce_domain' => 'paid-failed-'.uniqid().'.example.com',
            'name' => 'Paid Failed',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        $plan = Tenant::run($shop, function () use ($shop, $status, $kind, $heldSince, $nextChargeAt): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => self::CUSTOMER,
                'payplus_card_token_uid' => 'tok-'.uniqid(),
                'payplus_customer_uid' => 'pp-cust',
                'card_brand' => 'visa',
                'card_last_four' => '9125',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            CustomerConsent::create([
                'shopify_customer_id' => self::CUSTOMER,
                'consent_context' => $kind === PlanKind::INSTALLMENTS
                    ? CustomerConsent::CONTEXT_INSTALLMENTS
                    : CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now()->subYear(),
            ]);

            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => $shop->getKey(),
                'public_id' => 'PLN-'.uniqid(),
                'plan_kind' => $kind->value,
                'status' => $status->value,
                'payment_method_id' => $method->getKey(),
                'shopify_customer_id' => self::CUSTOMER,
                'customer_name' => 'דניאל צלניקר',
                // Installments: one ₪39 slice left of ₪78, so this charge is the last.
                'total_amount' => $kind === PlanKind::INSTALLMENTS ? 78 : 0,
                'total_charged' => $kind === PlanKind::INSTALLMENTS ? 39 : 0,
                'installment_amount' => 39,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => $nextChargeAt,
                'payment_failed_at' => $heldSince,
            ])->save();

            return $plan->fresh();
        });

        return [$shop, $plan];
    }
}
