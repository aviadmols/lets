<?php

namespace Tests\Feature\Settings;

use App\Domain\Installments\InstallmentQuote;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-shop billing policy (plan §4.7). Proves the tenant + money contract:
 *   - exactly one row per shop, lazily created by current() (firstOrCreate);
 *   - shop A's settings are NEVER read for shop B (BelongsToShop isolation);
 *   - the storefront quote is CLAMPED to the merchant's bounds server-side — a
 *     tampered deposit %, installment count, or frequency is forced in-bounds.
 */
final class MerchantBillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === current() ===

    public function test_current_lazily_creates_exactly_one_row_with_defaults(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        Tenant::run($shop, function () use ($shop): void {
            $first = MerchantBillingSettings::current();
            $second = MerchantBillingSettings::current();

            // Same row (firstOrCreate), one row only.
            $this->assertTrue($first->is($second));
            $this->assertSame(1, MerchantBillingSettings::query()->where('shop_id', $shop->getKey())->count());

            // Spec defaults (plan §4.7).
            $this->assertSame([4, 24, 72], $first->retryBackoffHours());
            $this->assertSame(3, $first->maxChargeAttempts());
            $this->assertSame(3, $first->failedPaymentGraceDays());
            $this->assertSame(10, $first->minDepositPercent());
            $this->assertNull($first->minDepositAmount());
            $this->assertSame(12, $first->maxInstallments());
            $this->assertTrue($first->lockFulfillmentUntilPaid());
            $this->assertTrue($first->allowsCustomerPause());
            $this->assertTrue($first->allowsCustomerCancel());
            $this->assertSame('v1', $first->termsVersion());
        });
    }

    // === tenant isolation (RELEASE BLOCKER) ===

    public function test_shop_a_settings_are_never_read_for_shop_b(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        // Shop A sets a high deposit floor + a low installment ceiling.
        Tenant::run($shopA, function (): void {
            $s = MerchantBillingSettings::current();
            $s->min_deposit_percent = 40;
            $s->max_installments = 2;
            $s->save();
        });

        // Shop B, fresh, must see ITS OWN spec defaults — never shop A's.
        Tenant::run($shopB, function (): void {
            $s = MerchantBillingSettings::current();
            $this->assertSame(10, $s->minDepositPercent());
            $this->assertSame(12, $s->maxInstallments());
        });

        // And shop A still carries its own values (no cross-contamination).
        Tenant::run($shopA, function (): void {
            $s = MerchantBillingSettings::current();
            $this->assertSame(40, $s->minDepositPercent());
            $this->assertSame(2, $s->maxInstallments());
        });

        // Two distinct rows, one per shop.
        $this->assertSame(2, MerchantBillingSettings::query()->withoutGlobalScopes()->count());
    }

    // === typed accessors ===

    public function test_allowed_frequencies_returns_billing_frequency_cases_and_falls_back(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        Tenant::run($shop, function (): void {
            $s = MerchantBillingSettings::current();
            $s->allowed_frequencies = [BillingFrequency::MONTHLY->value];
            $s->save();

            $this->assertSame([BillingFrequency::MONTHLY], $s->fresh()->allowedFrequencies());

            // An empty/garbage column falls back to the full selectable set.
            $s->allowed_frequencies = [];
            $s->save();
            $this->assertCount(
                count(MerchantBillingSettings::SELECTABLE_FREQUENCIES),
                $s->fresh()->allowedFrequencies(),
            );
        });
    }

    // === quote clamps (server-side money wall) ===

    public function test_quote_clamps_an_out_of_bounds_deposit_percent_up_to_the_merchant_floor(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $settings = Tenant::run($shop, function (): MerchantBillingSettings {
            $s = MerchantBillingSettings::current();
            $s->min_deposit_percent = 30; // merchant floor above the requested 5%
            $s->save();

            return $s->fresh();
        });

        // A tampered 5% request must be raised to the merchant's 30% floor.
        $quote = InstallmentQuote::build(
            totalAmount: 1000.0, depositPercent: 5, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
            bounds: $settings,
        );

        $this->assertSame(30, $quote->depositPercent);
        $this->assertSame(300.0, $quote->depositAmount);
    }

    public function test_quote_clamps_installment_count_down_to_the_merchant_ceiling(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $settings = Tenant::run($shop, function (): MerchantBillingSettings {
            $s = MerchantBillingSettings::current();
            $s->max_installments = 4; // ceiling well below the requested 36
            $s->save();

            return $s->fresh();
        });

        $quote = InstallmentQuote::build(
            totalAmount: 1000.0, depositPercent: 10, installments: 36,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
            bounds: $settings,
        );

        $this->assertSame(4, $quote->installments);
        $this->assertCount(4, $quote->schedule);
    }

    public function test_quote_forces_a_disallowed_frequency_into_the_allowed_set(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $settings = Tenant::run($shop, function (): MerchantBillingSettings {
            $s = MerchantBillingSettings::current();
            // The merchant only offers WEEKLY installments.
            $s->allowed_frequencies = [BillingFrequency::WEEKLY->value];
            $s->save();

            return $s->fresh();
        });

        // A request for MONTHLY (not offered) must fall back to the only allowed one.
        $quote = InstallmentQuote::build(
            totalAmount: 1000.0, depositPercent: 10, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
            bounds: $settings,
        );

        $this->assertSame(BillingFrequency::WEEKLY, $quote->frequency);
    }

    public function test_quote_honours_a_flat_minimum_deposit_amount(): void
    {
        $shop = $this->makeShop('a.myshopify.com');

        $settings = Tenant::run($shop, function (): MerchantBillingSettings {
            $s = MerchantBillingSettings::current();
            $s->min_deposit_percent = 10;       // 10% of 1000 = 100
            $s->min_deposit_amount = 250.0;     // but a flat 250 floor wins
            $s->save();

            return $s->fresh();
        });

        $quote = InstallmentQuote::build(
            totalAmount: 1000.0, depositPercent: 10, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
            bounds: $settings,
        );

        $this->assertSame(250.0, $quote->depositAmount);
    }

    public function test_quote_without_bounds_uses_only_the_value_object_clamps(): void
    {
        // No settings supplied (e.g. a bare unit context): behaviour is unchanged.
        $quote = InstallmentQuote::build(
            totalAmount: 1000.0, depositPercent: 5, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
        );

        $this->assertSame(InstallmentQuote::MIN_DEPOSIT_PERCENT, $quote->depositPercent);
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
}
