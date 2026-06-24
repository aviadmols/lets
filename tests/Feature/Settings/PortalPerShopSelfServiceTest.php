<?php

namespace Tests\Feature\Settings;

use App\Domain\Portal\PortalSignedUrlService;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer portal reads the PER-SHOP self-service flags from
 * MerchantBillingSettings (not platform config). Shop A turning pause/cancel OFF
 * must 403 only A's customers — shop B (defaults) is unaffected. This proves the
 * flags are tenant-scoped, not global.
 */
final class PortalPerShopSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private PortalSignedUrlService $urls;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->urls = app(PortalSignedUrlService::class);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_pause_is_denied_when_the_shops_setting_turns_it_off(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $s = MerchantBillingSettings::current();
            $s->allow_customer_pause = false;
            $s->save();

            return $this->makePlan($shop, customerId: 100);
        });

        $this->post($this->urls->pauseUrl($plan), ['plan' => $plan->public_id])
            ->assertStatus(403);

        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    public function test_cancel_is_denied_when_the_shops_setting_turns_it_off(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $s = MerchantBillingSettings::current();
            $s->allow_customer_cancel = false;
            $s->save();

            return $this->makePlan($shop, customerId: 100);
        });

        $this->post($this->urls->cancelUrl($plan), ['plan' => $plan->public_id])
            ->assertStatus(403);

        $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status);
    }

    public function test_one_shops_off_flag_does_not_affect_another_shop(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        // Shop A turns pause OFF; shop B keeps the default (on).
        $planA = Tenant::run($shopA, function () use ($shopA): InstallmentPlan {
            $s = MerchantBillingSettings::current();
            $s->allow_customer_pause = false;
            $s->save();

            return $this->makePlan($shopA, customerId: 100);
        });
        $planB = Tenant::run($shopB, fn (): InstallmentPlan => $this->makePlan($shopB, customerId: 100));

        // A is denied...
        $this->post($this->urls->pauseUrl($planA), ['plan' => $planA->public_id])
            ->assertStatus(403);
        // ...B is allowed (the flag is per shop, not global).
        $this->post($this->urls->pauseUrl($planB), ['plan' => $planB->public_id])
            ->assertRedirect();

        $this->assertSame(PlanStatus::ACTIVE, $planA->fresh()->status);
        $this->assertSame(PlanStatus::PAUSED, $planB->fresh()->status);
    }

    // === helpers (mirror CustomerPortalTest) ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function makePlan(Shop $shop, int $customerId): InstallmentPlan
    {
        $plan = new InstallmentPlan([
            'customer_id' => $customerId,
            'customer_email' => 'c'.$customerId.'@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'total_amount' => 300,
            'total_charged' => 100,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'next_charge_at' => now()->addDays(7),
            'public_id' => (string) Str::ulid(),
        ]);
        $plan->shop_id = $shop->getKey();
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan->fresh();
    }
}
