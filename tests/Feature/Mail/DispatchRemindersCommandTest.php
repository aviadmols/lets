<?php

namespace Tests\Feature\Mail;

use App\Mail\RecurringPaymentReminderMail;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Part B: the multi-tenant upcoming-charge reminder command. Sends within the
 * per-shop offset window, respects reminder_enabled, and is idempotent per cycle.
 */
final class DispatchRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_reminder_is_sent_for_a_plan_inside_the_offset_window(): void
    {
        Mail::fake();
        $shop = $this->makeShop('rem-in.myshopify.com');

        // Offset 72h; next charge in 24h => inside the window.
        $this->makePlan($shop, next: now()->addHours(24), offsetHours: 72);

        $this->artisan('payplus:dispatch-reminders')->assertExitCode(0);

        Mail::assertSent(RecurringPaymentReminderMail::class, 1);
    }

    public function test_reminder_is_not_sent_when_charge_is_outside_the_window(): void
    {
        Mail::fake();
        $shop = $this->makeShop('rem-out.myshopify.com');

        // Offset 72h; next charge in 10 days => outside the window.
        $this->makePlan($shop, next: now()->addDays(10), offsetHours: 72);

        $this->artisan('payplus:dispatch-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_reminder_respects_the_disabled_flag(): void
    {
        Mail::fake();
        $shop = $this->makeShop('rem-off.myshopify.com');

        $this->makePlan($shop, next: now()->addHours(12), offsetHours: 72, enabled: false);

        $this->artisan('payplus:dispatch-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_reminder_is_idempotent_across_runs_for_the_same_cycle(): void
    {
        Mail::fake();
        $shop = $this->makeShop('rem-once.myshopify.com');

        $this->makePlan($shop, next: now()->addHours(24), offsetHours: 72);

        $this->artisan('payplus:dispatch-reminders')->assertExitCode(0);
        $this->artisan('payplus:dispatch-reminders')->assertExitCode(0);

        // Two runs, one cycle => exactly one reminder.
        Mail::assertSent(RecurringPaymentReminderMail::class, 1);
    }

    public function test_reminders_are_isolated_across_shops(): void
    {
        Mail::fake();
        $shopA = $this->makeShop('rem-a.myshopify.com');
        $shopB = $this->makeShop('rem-b.myshopify.com');

        // A is due inside its window; B is not.
        $this->makePlan($shopA, next: now()->addHours(24), offsetHours: 72, email: 'a@example.com');
        $this->makePlan($shopB, next: now()->addDays(30), offsetHours: 72, email: 'b@example.com');

        $this->artisan('payplus:dispatch-reminders')->assertExitCode(0);

        Mail::assertSent(RecurringPaymentReminderMail::class, function (RecurringPaymentReminderMail $mail) use ($shopA): bool {
            return $mail->shop->is($shopA) && $mail->hasTo('a@example.com');
        });
        Mail::assertSent(RecurringPaymentReminderMail::class, 1); // only A
    }

    private function makeShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Store '.$domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }

    private function makePlan(
        Shop $shop,
        \Illuminate\Support\Carbon $next,
        int $offsetHours,
        bool $enabled = true,
        string $email = 'dana@example.com',
    ): InstallmentPlan {
        return Tenant::run($shop, function () use ($next, $offsetHours, $enabled, $email): InstallmentPlan {
            $settings = MerchantMailSettings::current();
            $settings->reminder_enabled = $enabled;
            $settings->reminder_offset_hours = $offsetHours;
            $settings->save();

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'installment_amount' => 49.90,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'customer_name' => 'Dana',
                'customer_email' => $email,
                'next_charge_at' => $next,
                'meta' => ['product_title' => 'Subscription'],
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        });
    }
}
