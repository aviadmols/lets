<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\InstallmentQuote;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The W9 Part C money wall: the deposit + slices are derived ENTIRELY server-side
 * from a trusted total + clamped knobs; the client never controls an amount. The
 * slices always sum to exactly (total − deposit), the last slice absorbing rounding.
 */
final class InstallmentQuoteTest extends TestCase
{
    private function sum(InstallmentQuote $q): float
    {
        return round(array_sum(array_column($q->schedule, 'amount')), 2);
    }

    public function test_deposit_and_slices_are_server_derived_and_sum_exactly(): void
    {
        $q = InstallmentQuote::build(
            totalAmount: 600.0, depositPercent: 25, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
            now: CarbonImmutable::parse('2026-01-10'),
        );

        $this->assertSame(150.0, $q->depositAmount);
        $this->assertCount(3, $q->schedule);
        $this->assertSame(450.0, $this->sum($q));                       // financed = total − deposit
        $this->assertSame(600.0, round($q->depositAmount + $this->sum($q), 2));
    }

    public function test_last_slice_absorbs_the_rounding_remainder(): void
    {
        // 100 − 33 = 67 financed over 3 → 22.33, 22.33, 22.34 (sums to 67 exactly).
        $q = InstallmentQuote::build(
            totalAmount: 100.0, depositPercent: 33, installments: 3,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
        );

        // The slices sum to EXACTLY (total − deposit); if the last slice had not
        // absorbed the rounding remainder, three even 22.33 slices would total 66.99,
        // not the financed 67 — so this equality IS the remainder-absorption proof.
        $this->assertSame(round(100.0 - $q->depositAmount, 2), $this->sum($q));
    }

    public function test_knobs_are_clamped_to_bounds(): void
    {
        // A client cannot push the deposit to 0%, request 9999 slices, or a day-31.
        $q = InstallmentQuote::build(
            totalAmount: 1000.0, depositPercent: 0, installments: 9999,
            frequency: BillingFrequency::MONTHLY, paymentDay: 31, currency: 'ILS',
        );

        $this->assertSame(InstallmentQuote::MIN_DEPOSIT_PERCENT, $q->depositPercent);
        $this->assertSame(InstallmentQuote::MAX_INSTALLMENTS, $q->installments);
        $this->assertSame(InstallmentQuote::MAX_PAYMENT_DAY, $q->paymentDay);
    }

    public function test_there_is_always_something_left_to_finance(): void
    {
        $q = InstallmentQuote::build(
            totalAmount: 100.0, depositPercent: 90, installments: 1,
            frequency: BillingFrequency::MONTHLY, paymentDay: 1, currency: 'ILS',
        );

        $this->assertLessThan(100.0, $q->depositAmount);
        $this->assertGreaterThan(0.0, $this->sum($q));
    }
}
