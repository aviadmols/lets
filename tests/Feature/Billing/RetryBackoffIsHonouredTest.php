<?php

namespace Tests\Feature\Billing;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * THE RETRY BACKOFF IS ACTUALLY HONOURED — a guard that existed, was documented,
 * and did nothing.
 *
 * DispatchDuePlansCommand excludes a plan whose slot is waiting out its retry. The
 * filter was written, its comment predicted the exact failure ("the backoff would
 * be written and never honoured, and one debt would be asked for hundreds of times
 * a day"), and it never fired — because `acrossAllTenants()` takes the tenant scope
 * off the PLAN query while the nested whereDoesntHave builds a FRESH
 * InstallmentPayment query that keeps its own. With no tenant bound the scope
 * resolved to `shop_id = NULL`, matched nothing, and the "has no waiting retry"
 * test was true for every plan alive.
 *
 * A scope that fails closed is right everywhere else. Inverted by whereDoesntHave
 * it fails OPEN, which is the whole lesson: 109 cards on a real migration were each
 * asked SEVEN times between 18:07 and 18:35 — a seven-day ladder spent in
 * twenty-eight minutes, every subscriber held before they could have fixed
 * anything, and seven declines in half an hour pushed at issuers that read that
 * pattern as fraud.
 *
 * These run with NO TENANT BOUND on purpose. That is the state the scheduler
 * actually runs in, and binding one in the test is what would have let the bug
 * through.
 */
final class RetryBackoffIsHonouredTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** THE REGRESSION. A plan inside its backoff window is not asked again. */
    public function test_a_plan_waiting_out_its_retry_is_not_dispatched(): void
    {
        Queue::fake();

        [$shop, $plan] = $this->duePlan('waiting.example.com');
        $this->slot($plan, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        Tenant::clear(); // the scheduler's real world: no tenant bound

        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertNotPushed(ChargeJob::class);
    }

    /** And once the window closes, it IS asked again. */
    public function test_a_plan_whose_retry_window_has_passed_is_dispatched(): void
    {
        Queue::fake();

        [$shop, $plan] = $this->duePlan('elapsed.example.com');
        $this->slot($plan, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->subMinute());

        Tenant::clear();

        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ChargeJob::class, 1);
    }

    /**
     * The scheduler's cadence cannot burn the ladder. Seven ticks five minutes
     * apart must produce ONE attempt, not seven — which is precisely what the live
     * store saw.
     */
    public function test_seven_scheduler_ticks_inside_one_window_ask_once(): void
    {
        Queue::fake();

        [$shop, $plan] = $this->duePlan('ladder.example.com');

        Tenant::clear();

        // Tick one: nothing is waiting yet, so the plan is asked.
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        Queue::assertPushed(ChargeJob::class, 1);

        // The charge failed and the engine wrote tomorrow's retry.
        $this->slot($plan, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        // Six more ticks across the next half hour.
        for ($tick = 0; $tick < 6; $tick++) {
            $this->travel(5)->minutes();
            $this->artisan('payplus:dispatch-due')->assertSuccessful();
        }

        Queue::assertPushed(ChargeJob::class, 1);
    }

    /** A hold on ANOTHER shop's plan does not silence this one. */
    public function test_the_hold_is_read_per_plan_not_per_tenant(): void
    {
        Queue::fake();

        [, $waiting] = $this->duePlan('multi-a.example.com');
        $this->slot($waiting, PaymentStatus::RETRY_SCHEDULED, nextRetry: now()->addDay());

        [, $ready] = $this->duePlan('multi-b.example.com');

        Tenant::clear();

        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        // Exactly one: the second shop's plan, which is owed and not waiting.
        Queue::assertPushed(ChargeJob::class, 1);
        Queue::assertPushed(
            ChargeJob::class,
            fn (ChargeJob $job): bool => $job->planId === (int) $ready->getKey(),
        );
    }

    /** A settled slot is not a hold — a paid plan's history must not mute it. */
    public function test_a_succeeded_slot_does_not_hold_the_plan(): void
    {
        Queue::fake();

        [, $plan] = $this->duePlan('settled.example.com');
        // Succeeded, and carrying a stale future retry date from an earlier attempt.
        $this->slot($plan, PaymentStatus::SUCCEEDED, nextRetry: now()->addDay());

        Tenant::clear();

        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ChargeJob::class, 1);
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function duePlan(string $domain): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        return [$shop, Tenant::run($shop, static function (): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => Tenant::id(),
                'public_id' => 'PLN-'.uniqid(),
                'customer_name' => 'Dana',
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 39,
                'currency' => 'ILS',
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => now()->subMinute(), // owed
            ])->save();

            return $plan;
        })];
    }

    private function slot(InstallmentPlan $plan, PaymentStatus $status, ?Carbon $nextRetry = null): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'sequence' => 1,
            'amount' => 39,
            'currency' => 'ILS',
            'status' => $status->value,
            'attempt_count' => 1,
            'next_retry_at' => $nextRetry,
        ])->save();
    }
}
