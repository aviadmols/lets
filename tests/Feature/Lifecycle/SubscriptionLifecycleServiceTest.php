<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Mail\PlanCancelledMail;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Exceptions\IllegalTransitionException;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave-1a subscription lifecycle: PAUSE / RESUME / CANCEL. State-only + audited via
 * the guarded state machine (which writes the Timeline). No money moves here. Verifies
 * the legal transitions, the resume clock-snap, the cancellation email + clock-stop,
 * the illegal-move guard, and tenant isolation.
 */
final class SubscriptionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SubscriptionLifecycleService
    {
        return new SubscriptionLifecycleService;
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_pause_moves_active_to_paused_and_writes_a_timeline_event(): void
    {
        $shop = $this->makeShop('pause.myshopify.com');
        $plan = $this->makePlan($shop, PlanStatus::ACTIVE);

        $result = Tenant::run($shop, fn (): InstallmentPlan => $this->service()->pause($plan, 'customer asked'));

        $this->assertSame(PlanStatus::PAUSED, $result->fresh()->status);
        // ActivityEvent is BelongsToShop-scoped → count within the tenant.
        $events = Tenant::run($shop, fn (): int => ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', 'status_changed')
            ->count());
        $this->assertSame(1, $events);
    }

    public function test_resume_moves_paused_to_active_and_snaps_a_past_due_date_to_today(): void
    {
        $shop = $this->makeShop('resume.myshopify.com');
        $plan = $this->makePlan($shop, PlanStatus::PAUSED, ['next_charge_at' => now()->subDays(10)]);

        $result = Tenant::run($shop, fn (): InstallmentPlan => $this->service()->resume($plan));

        $fresh = $result->fresh();
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
        $this->assertTrue($fresh->next_charge_at->isToday(), 'a past due date is snapped to today on resume');
    }

    public function test_cancel_stops_the_clock_and_emails_the_customer(): void
    {
        Mail::fake();
        $shop = $this->makeShop('cancel.myshopify.com');
        $plan = $this->makePlan($shop, PlanStatus::ACTIVE, ['customer_email' => 'buyer@example.com']);

        $result = Tenant::run($shop, fn (): InstallmentPlan => $this->service()->cancel($plan, 'too expensive'));

        $fresh = $result->fresh();
        $this->assertSame(PlanStatus::CANCELLED, $fresh->status);
        $this->assertNull($fresh->next_charge_at);
        Mail::assertSent(PlanCancelledMail::class);
    }

    public function test_cancel_without_a_customer_email_sends_nothing(): void
    {
        Mail::fake();
        $shop = $this->makeShop('nomail.myshopify.com');
        $plan = $this->makePlan($shop, PlanStatus::ACTIVE, ['customer_email' => null]);

        Tenant::run($shop, fn (): InstallmentPlan => $this->service()->cancel($plan));

        Mail::assertNothingSent();
    }

    public function test_an_illegal_transition_throws(): void
    {
        $shop = $this->makeShop('illegal.myshopify.com');
        $plan = $this->makePlan($shop, PlanStatus::COMPLETED);

        $this->expectException(IllegalTransitionException::class);
        Tenant::run($shop, fn (): InstallmentPlan => $this->service()->cancel($plan));
    }

    public function test_is_tenant_scoped_cannot_act_on_another_shops_plan(): void
    {
        $shopA = $this->makeShop('iso-a.myshopify.com');
        $planA = $this->makePlan($shopA, PlanStatus::ACTIVE);
        $shopB = $this->makeShop('iso-b.myshopify.com');

        // Bound to shop B, shop A's plan is invisible → the locked re-read 404s.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Tenant::run($shopB, fn (): InstallmentPlan => $this->service()->pause($planA));
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function makePlan(Shop $shop, PlanStatus $status, array $attrs = []): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $status, $attrs): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill(array_merge([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'total_charged' => 0,
                'installment_amount' => 25,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'public_id' => (string) Str::ulid(),
                'customer_email' => 'cust@example.com',
                'next_charge_at' => now()->addDays(7),
            ], $attrs));
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => $status->value,
            ])->save();

            return $plan->fresh();
        });
    }
}
