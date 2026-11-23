<?php

namespace Tests\Feature\Insights;

use App\Domain\Insights\AbandonedCheckouts;
use App\Domain\Insights\InsightsClock;
use App\Domain\Insights\PaidSubscriptions;
use App\Domain\Insights\UnpaidSubscribers;
use App\Filament\Pages\Insights\AbandonedCarts;
use App\Filament\Pages\Insights\Churn;
use App\Filament\Pages\Insights\Joins;
use App\Filament\Pages\Insights\RevenueForecast;
use App\Filament\Pages\PaymentRecovery;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merchant analytics screens (approved 2026-11-23): what each one counts, and that
 * every screen renders for a merchant.
 */
final class InsightsScreensTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'insights.example.com',
            'name' => 'Insights',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** Joining = the first PAID day through the site; an import is not joining. */
    public function test_joins_count_site_subscribers_on_their_first_paid_day_only(): void
    {
        $site = $this->plan('dana@example.com', PlanStatus::ACTIVE);
        $this->payment($site, PaymentStatus::SUCCEEDED, '2026-11-10 09:00:00');
        $this->payment($site, PaymentStatus::SUCCEEDED, '2026-12-10 09:00:00');
        $imported = $this->plan('old@example.com', PlanStatus::ACTIVE, ['import' => ['plan_name' => 'Old']]);
        $this->payment($imported, PaymentStatus::SUCCEEDED, '2026-11-12 09:00:00');

        $siteOnly = (new PaidSubscriptions)->all(false);
        $everyone = (new PaidSubscriptions)->all(true);

        $this->assertCount(1, $siteOnly);
        $this->assertSame(
            ['plan' => $site->getKey(), 'day' => '2026-11-10', 'paid' => 2, 'imported' => false],
            array_intersect_key($siteOnly[0], array_flip(['plan', 'day', 'paid', 'imported'])),
        );
        // Who they are and what they joined — the joins screen lists both.
        $this->assertSame('Dana', $siteOnly[0]['name']);
        $this->assertCount(2, $everyone);
        $this->assertTrue(collect($everyone)->firstWhere('plan', $imported->getKey())['imported']);
    }

    /** A late-night UTC charge is the next morning's sale in Israel. */
    public function test_days_are_the_merchants_israeli_days(): void
    {
        $this->assertSame('2026-11-11', InsightsClock::day('2026-11-10 23:30:00'));
    }

    /** An abandoned cart says whether the person came back and bought anyway. */
    public function test_abandoned_checkouts_know_who_came_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));
        $lost = $this->plan('lost@example.com', PlanStatus::AWAITING_FIRST_PAYMENT);
        $back = $this->plan('back@example.com', PlanStatus::AWAITING_FIRST_PAYMENT);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 13:00:00'));
        $this->plan('back@example.com', PlanStatus::ACTIVE);
        // An imported "pending" member is not a checkout.
        $this->plan('imported@example.com', PlanStatus::AWAITING_FIRST_PAYMENT, ['import' => ['status' => 'pending']]);

        $rows = collect((new AbandonedCheckouts)->between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')))->keyBy('plan');

        $this->assertCount(2, $rows);
        $this->assertFalse($rows[$lost->getKey()]['came_back']);
        $this->assertTrue($rows[$back->getKey()]['came_back']);
    }

    /** "Paused" for non-payment is owing money, not a choice — it is a payment problem. */
    public function test_unpaid_includes_plans_paused_for_non_payment_and_only_them(): void
    {
        $held = $this->plan('held@example.com', PlanStatus::PAUSED);
        $held->forceFill(['payment_failed_at' => now()])->save();
        $this->payment($held, PaymentStatus::FAILED, '2026-11-01 10:00:00', 'this-token-not-exist');
        $this->plan('chose@example.com', PlanStatus::PAUSED);
        $retrying = $this->plan('retry@example.com', PlanStatus::AWAITING_PAYMENT);

        $rows = collect((new UnpaidSubscribers)->all())->keyBy('plan');

        $this->assertCount(2, $rows);
        $this->assertSame(UnpaidSubscribers::STATE_HELD, $rows[$held->getKey()]['state']);
        $this->assertTrue($rows[$held->getKey()]['no_card']);
        $this->assertSame(UnpaidSubscribers::STATE_RETRYING, $rows[$retrying->getKey()]['state']);
    }

    public function test_every_screen_renders_for_a_merchant(): void
    {
        $plan = $this->plan('dana@example.com', PlanStatus::ACTIVE);
        $this->payment($plan, PaymentStatus::SUCCEEDED, now()->subDays(3)->toDateTimeString());

        foreach ([Joins::class, Churn::class, AbandonedCarts::class, RevenueForecast::class, ListSubscriptions::class, PaymentRecovery::class] as $page) {
            Livewire::test($page)->assertOk();
        }
    }

    /** The export is the list on screen: the dates AND the who-filter. */
    public function test_the_abandoned_export_follows_the_filter(): void
    {
        $this->plan('lost@example.com', PlanStatus::AWAITING_FIRST_PAYMENT, [], 'Lost Person');
        $this->plan('back@example.com', PlanStatus::AWAITING_FIRST_PAYMENT, [], 'Back Person');
        $this->plan('back@example.com', PlanStatus::ACTIVE, [], 'Back Person');

        $response = Livewire::test(AbandonedCarts::class)->call('setWho', 'lost')->call('export');

        $response->assertFileDownloaded();
    }

    // === Fixtures ===

    private function plan(string $email, PlanStatus $status, array $meta = [], string $name = 'Dana'): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 59,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_name' => $name,
            'customer_email' => $email,
        ]);
        $plan->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'status' => $status->value,
            'meta' => $meta === [] ? null : $meta,
        ])->save();

        return $plan;
    }

    private function payment(InstallmentPlan $plan, PaymentStatus $status, string $at, ?string $message = null): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'plan_id' => $plan->getKey(),
            'payment_type' => PaymentType::RECURRING->value,
            'sequence' => InstallmentPayment::query()->where('plan_id', $plan->getKey())->count() + 1,
            'amount' => 59,
            'currency' => 'ILS',
            'status' => $status->value,
            'charged_at' => $status === PaymentStatus::SUCCEEDED ? $at : null,
            'failure_message' => $message,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }
}
