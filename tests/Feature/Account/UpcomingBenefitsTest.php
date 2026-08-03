<?php

namespace Tests\Feature\Account;

use App\Domain\Account\UpcomingBenefits;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The benefit timeline. The property that matters is not "it lists things" — it
 * is that every DATE it shows is derived from a real schedule, and that anything
 * without one renders as progress rather than a guess a shopper will hold us to.
 */
final class UpcomingBenefitsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private UpcomingBenefits $benefits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'benefits.myshopify.com',
            'name' => 'Benefits',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);

        $this->benefits = app(UpcomingBenefits::class);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_next_delivery_carries_the_plans_real_date(): void
    {
        $plan = $this->recurring(nextInDays: 12);

        $row = $this->find($plan, UpcomingBenefits::KIND_NEXT_DELIVERY);

        $this->assertSame(now()->addDays(12)->toDateString(), $row['at']);
    }

    /**
     * The step-up date is the whole reason this section exists: the shopper should
     * learn the new price from us, not from their bank statement.
     */
    public function test_the_intro_discount_end_date_is_projected_from_the_window(): void
    {
        // A 3-cycle window with 2 charges already taken: one discounted charge is
        // still to come, so the first full-price one is a month after that.
        $plan = $this->recurring(nextInDays: 10, discountCycles: 3, regular: 119.0, succeeded: 2);

        $row = $this->find($plan, UpcomingBenefits::KIND_INTRO_ENDING);

        $this->assertSame(now()->addDays(10)->addMonth()->toDateString(), $row['at']);
        $this->assertSame(119.0, $row['amount']);
        $this->assertSame(UpcomingBenefits::TONE_WARN, $row['tone']);
    }

    public function test_a_plan_with_no_intro_window_reports_no_step_up(): void
    {
        $plan = $this->recurring(nextInDays: 10);

        $this->assertNull($this->maybeFind($plan, UpcomingBenefits::KIND_INTRO_ENDING));
    }

    public function test_a_paused_plan_contributes_no_dates_at_all(): void
    {
        $plan = $this->recurring(nextInDays: 10);
        $plan->forceFill(['status' => PlanStatus::PAUSED->value])->save();

        // A paused plan's clock is stopped; its stale next_charge_at is not a date
        // anything will actually happen on.
        $this->assertSame([], $this->rows($plan));
    }

    public function test_a_queued_next_order_is_announced_with_the_delivery_date(): void
    {
        $plan = $this->recurring(nextInDays: 7);
        $plan->forceFill(['meta' => [InstallmentPlan::META_NEXT_ORDER => [
            'line_items' => [['name' => 'Extra beans', 'quantity' => 1, 'unit_price' => 30]],
            'amount' => 119.0,
        ]]])->save();

        $row = $this->find($plan, UpcomingBenefits::KIND_NEXT_ORDER_EXTRA);

        $this->assertSame(now()->addDays(7)->toDateString(), $row['at']);
        $this->assertStringContainsString('Extra beans', $row['label']);
    }

    public function test_an_installments_plan_projects_its_final_payment(): void
    {
        $plan = $this->installments(nextInDays: 5, total: 400.0, charged: 100.0, per: 100.0);

        $row = $this->find($plan, UpcomingBenefits::KIND_PLAN_COMPLETES);

        // 300 left at 100/month = three charges; the next is one of them, so the
        // last lands two months after it.
        $this->assertSame(now()->addDays(5)->addMonths(2)->toDateString(), $row['at']);
    }

    public function test_the_birthday_grant_is_dated_from_the_stored_birthday(): void
    {
        $settings = MerchantLoyaltySettings::current();
        $settings->forceFill(['enabled' => true, 'birthday_points' => 250])->save();

        $account = $this->member(birthday: now()->addDays(40)->toDateString());

        $rows = $this->benefits->for(new Collection, $account, $settings->refresh());
        $row = $this->pick($rows, UpcomingBenefits::KIND_BIRTHDAY_POINTS);

        $this->assertSame(now()->addDays(40)->toDateString(), $row['at']);
        $this->assertSame(250, $row['points']);
    }

    public function test_tier_progress_carries_a_gap_and_deliberately_no_date(): void
    {
        $settings = MerchantLoyaltySettings::current();
        $settings->forceFill(['enabled' => true])->save();

        $tier = new LoyaltyTier;
        $tier->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => 'Gold',
            'min_spend' => 1000,
            'points_multiplier' => 1,
            'position' => 1,
        ])->save();

        $account = $this->member(spend: 400.0, tierId: (int) $tier->getKey());

        $row = $this->pick(
            $this->benefits->for(new Collection, $account, $settings->refresh()),
            UpcomingBenefits::KIND_TIER_PROGRESS,
        );

        // Tiers are SPEND-based. There is no honest date for "when will you have
        // spent another ₪600", so the row carries a gap and nothing else.
        $this->assertNull($row['at']);
        $this->assertSame(600.0, $row['remaining']);
    }

    public function test_dated_rows_sort_before_undated_ones(): void
    {
        $settings = MerchantLoyaltySettings::current();
        $settings->forceFill(['enabled' => true, 'birthday_points' => 100])->save();

        $tier = new LoyaltyTier;
        $tier->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => 'Gold',
            'min_spend' => 1000,
            'points_multiplier' => 1,
            'position' => 1,
        ])->save();

        $plan = $this->recurring(nextInDays: 3);
        $account = $this->member(spend: 100.0, tierId: (int) $tier->getKey(), birthday: now()->addDays(90)->toDateString());

        $rows = $this->benefits->for(collect([$plan]), $account, $settings->refresh());

        $dates = array_column($rows, 'at');
        $firstUndated = array_search(null, $dates, true);

        $this->assertIsInt($firstUndated);
        // Everything before the first undated row must be dated AND ascending.
        $dated = array_slice($dates, 0, $firstUndated);
        $sorted = $dated;
        sort($sorted);
        $this->assertSame($sorted, $dated);
        // And nothing dated may appear after it.
        $this->assertSame([], array_filter(array_slice($dates, $firstUndated), static fn ($d) => $d !== null));
    }

    // === Helpers ===

    private function rows(InstallmentPlan $plan): array
    {
        return $this->benefits->for(collect([$plan]), null, MerchantLoyaltySettings::current());
    }

    private function find(InstallmentPlan $plan, string $kind): array
    {
        $row = $this->maybeFind($plan, $kind);
        $this->assertNotNull($row, "No [{$kind}] row was produced.");

        return $row;
    }

    private function maybeFind(InstallmentPlan $plan, string $kind): ?array
    {
        foreach ($this->rows($plan) as $row) {
            if ($row['kind'] === $kind) {
                return $row;
            }
        }

        return null;
    }

    private function pick(array $rows, string $kind): array
    {
        foreach ($rows as $row) {
            if ($row['kind'] === $kind) {
                return $row;
            }
        }

        $this->fail("No [{$kind}] row was produced.");
    }

    private function recurring(int $nextInDays, ?int $discountCycles = null, ?float $regular = null, int $succeeded = 0): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.uniqid(),
            'external_customer_id' => '7',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 89,
            'regular_amount' => $regular,
            'discount_cycles' => $discountCycles,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays($nextInDays)->startOfDay(),
        ])->save();

        for ($i = 0; $i < $succeeded; $i++) {
            $payment = new InstallmentPayment;
            $payment->forceFill([
                'shop_id' => $this->shop->getKey(),
                'plan_id' => $plan->getKey(),
                'sequence' => $i,
                'amount' => 89,
                'currency' => 'ILS',
                'status' => PaymentStatus::SUCCEEDED->value,
                'charged_at' => now()->subMonths($succeeded - $i),
            ])->save();
        }

        return $plan->refresh();
    }

    private function installments(int $nextInDays, float $total, float $charged, float $per): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.uniqid(),
            'external_customer_id' => '7',
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => $total,
            'total_charged' => $charged,
            'installment_amount' => $per,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays($nextInDays)->startOfDay(),
        ])->save();

        return $plan;
    }

    private function member(float $spend = 0.0, ?int $tierId = null, ?string $birthday = null): LoyaltyAccount
    {
        $account = new LoyaltyAccount;
        $account->forceFill([
            'shop_id' => $this->shop->getKey(),
            'customer_ref' => '7',
            'customer_email' => 'dana@example.com',
            'points_balance' => 0,
            'lifetime_spend' => $spend,
            'tier_id' => $tierId,
            'birthday' => $birthday,
        ])->save();

        return $account->refresh();
    }
}
