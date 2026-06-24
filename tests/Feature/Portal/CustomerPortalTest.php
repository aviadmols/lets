<?php

namespace Tests\Feature\Portal;

use App\Domain\Portal\PortalSignedUrlService;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CUSTOMER PORTAL — the signed magic link is the only auth. These tests prove the
 * SECURITY contract: a valid link renders ONLY the signed customer's plans; a forged
 * or expired signature 403s; shop A's link never surfaces shop B's plans; signed
 * pause/cancel transition the plan via the reused lifecycle service and are REJECTED
 * for a plan that is not the signed customer's (cross-customer / cross-shop); and the
 * merchant allow flag gates pause/cancel.
 */
final class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private PortalSignedUrlService $urls;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); // the lifecycle cancel email must not touch real transport.
        $this->urls = app(PortalSignedUrlService::class);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === show ===

    public function test_valid_signed_link_renders_only_the_signed_customers_plans(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        [$mine, $other] = Tenant::run($shop, function () use ($shop): array {
            $mine = $this->makePlan($shop, customerId: 100, kind: PlanKind::RECURRING, status: PlanStatus::ACTIVE);
            // A DIFFERENT customer on the SAME shop — must never appear on my link.
            $other = $this->makePlan($shop, customerId: 200, kind: PlanKind::RECURRING, status: PlanStatus::ACTIVE);

            return [$mine, $other];
        });

        $response = $this->get($this->urls->showUrl($mine));

        $response->assertOk();
        $response->assertSee('#'.$mine->public_id);
        $response->assertDontSee('#'.$other->public_id);
    }

    public function test_forged_signature_is_rejected_with_403(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        $plan = Tenant::run($shop, fn () => $this->makePlan($shop, customerId: 100));

        $tampered = $this->urls->showUrl($plan).'&tampered=1';

        $this->get($tampered)->assertStatus(403);
    }

    public function test_expired_signature_is_rejected_with_403(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        $plan = Tenant::run($shop, fn () => $this->makePlan($shop, customerId: 100));

        $url = $this->urls->showUrl($plan);

        // Walk past the TTL (default 7 days) — the link must expire.
        $this->travel(8)->days();

        $this->get($url)->assertStatus(403);
    }

    public function test_shop_a_link_never_shows_shop_b_plans(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        // Same customer id on BOTH shops — proves the shop binding, not just the id.
        $planA = Tenant::run($shopA, fn () => $this->makePlan($shopA, customerId: 100));
        $planB = Tenant::run($shopB, fn () => $this->makePlan($shopB, customerId: 100));

        $response = $this->get($this->urls->showUrl($planA));

        $response->assertOk();
        $response->assertSee('#'.$planA->public_id);
        $response->assertDontSee('#'.$planB->public_id);
    }

    // === pause / resume / cancel ===

    public function test_signed_pause_transitions_the_plan(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        $plan = Tenant::run($shop, fn () => $this->makePlan($shop, customerId: 100, status: PlanStatus::ACTIVE));

        $this->post($this->urls->pauseUrl($plan), ['plan' => $plan->public_id])
            ->assertRedirect();

        $this->assertSame(PlanStatus::PAUSED, $plan->fresh()->status);
    }

    public function test_signed_cancel_transitions_the_plan(): void
    {
        $shop = $this->makeShop('a.myshopify.com');
        $plan = Tenant::run($shop, fn () => $this->makePlan($shop, customerId: 100, status: PlanStatus::ACTIVE));

        $this->post($this->urls->cancelUrl($plan), ['plan' => $plan->public_id])
            ->assertRedirect();

        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
    }

    public function test_pause_is_rejected_for_a_plan_that_is_not_the_signed_customers(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        [$mine, $victim] = Tenant::run($shop, function () use ($shop): array {
            $mine = $this->makePlan($shop, customerId: 100, status: PlanStatus::ACTIVE);
            $victim = $this->makePlan($shop, customerId: 200, status: PlanStatus::ACTIVE);

            return [$mine, $victim];
        });

        // Sign for MY customer, then point the body at the OTHER customer's plan.
        $this->post($this->urls->pauseUrl($mine), ['plan' => $victim->public_id])
            ->assertNotFound();

        // The victim's plan is untouched.
        $this->assertSame(PlanStatus::ACTIVE, $victim->fresh()->status);
    }

    public function test_cancel_is_rejected_across_shops(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        $planA = Tenant::run($shopA, fn () => $this->makePlan($shopA, customerId: 100, status: PlanStatus::ACTIVE));
        $planB = Tenant::run($shopB, fn () => $this->makePlan($shopB, customerId: 100, status: PlanStatus::ACTIVE));

        // Shop A's signed cancel link, body pointed at Shop B's plan public_id.
        $this->post($this->urls->cancelUrl($planA), ['plan' => $planB->public_id])
            ->assertNotFound();

        $this->assertSame(PlanStatus::ACTIVE, $planB->fresh()->status);
    }

    public function test_pause_respects_the_merchant_allow_flag(): void
    {
        config()->set('portal.allow_customer_pause', false);

        $shop = $this->makeShop('a.myshopify.com');
        $plan = Tenant::run($shop, fn () => $this->makePlan($shop, customerId: 100, status: PlanStatus::ACTIVE));

        $this->post($this->urls->pauseUrl($plan), ['plan' => $plan->public_id])
            ->assertStatus(403);

        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    public function test_cancel_respects_the_merchant_allow_flag(): void
    {
        config()->set('portal.allow_customer_cancel', false);

        $shop = $this->makeShop('a.myshopify.com');
        $plan = Tenant::run($shop, fn () => $this->makePlan($shop, customerId: 100, status: PlanStatus::ACTIVE));

        $this->post($this->urls->cancelUrl($plan), ['plan' => $plan->public_id])
            ->assertStatus(403);

        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    // === helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function makePlan(
        Shop $shop,
        int $customerId,
        PlanKind $kind = PlanKind::RECURRING,
        PlanStatus $status = PlanStatus::ACTIVE,
    ): InstallmentPlan {
        $plan = new InstallmentPlan([
            'customer_id' => $customerId,
            'customer_email' => 'c'.$customerId.'@example.com',
            'plan_kind' => $kind->value,
            'total_amount' => 300,
            'total_charged' => 100,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'next_charge_at' => now()->addDays(7),
            'public_id' => (string) Str::ulid(),
        ]);
        $plan->shop_id = $shop->getKey();
        $plan->forceFill(['status' => $status->value])->save();

        // One succeeded slot so the history table renders + the page has content.
        $payment = new InstallmentPayment([
            'plan_id' => $plan->getKey(),
            'payment_type' => PaymentType::INSTALLMENT->value,
            'sequence' => 1,
            'amount' => 100,
            'currency' => 'ILS',
            'charged_at' => now()->subDays(7),
        ]);
        $payment->shop_id = $shop->getKey();
        $payment->forceFill(['status' => PaymentStatus::SUCCEEDED->value])->save();

        return $plan->fresh();
    }
}
