<?php

namespace Tests\Feature\Loyalty;

use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The sharer gets 5% of the purchase they referred, as points."
 *
 * The whole value of the setting is that 5% MEANS 5% of the money — including
 * after the merchant changes their redemption rate. These tests pin that, and
 * the one case where it cannot hold.
 */
final class ReferralPercentTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'refpct.myshopify.com',
            'name' => 'Ref',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_five_percent_of_an_order_is_worth_five_percent_of_the_money(): void
    {
        // 100 points buy ₪10, so a point is worth ₪0.10 and ₪1 is 10 points.
        $settings = $this->settings(percent: 5, redeemPoints: 100, redeemAmount: 10.0);

        // 5% of ₪200 = ₪10 of value = 100 points.
        $this->assertSame(100, $settings->referralRewardFor(200.0));
    }

    public function test_the_percentage_holds_when_the_merchant_changes_their_rate(): void
    {
        $generous = $this->settings(percent: 5, redeemPoints: 100, redeemAmount: 10.0);
        $stingy = $this->settings(percent: 5, redeemPoints: 500, redeemAmount: 10.0);

        // Both award 5% of ₪200 = ₪10 of credit. The POINTS differ because a
        // point is worth less in the second shop; the MONEY does not.
        $this->assertSame(10.0, $generous->creditFor($generous->referralRewardFor(200.0))['amount']);
        $this->assertSame(10.0, $stingy->creditFor($stingy->referralRewardFor(200.0))['amount']);
    }

    public function test_display_only_points_fall_back_to_the_earn_rate(): void
    {
        // Redemption off: a point buys nothing, so there is no money value to
        // convert through. The member gets what they would have earned spending
        // that much themselves — a different promise, and the admin says so.
        $settings = $this->settings(percent: 10, redeemPoints: 100, redeemAmount: 0.0, pointsPerCurrency: 3);

        // 10% of ₪200 = ₪20 of spend → 20 × 3 = 60 points.
        $this->assertSame(60, $settings->referralRewardFor(200.0));
    }

    public function test_all_three_rewards_combine(): void
    {
        $settings = $this->settings(percent: 5, redeemPoints: 100, redeemAmount: 10.0);
        $settings->forceFill([
            'referral_points_per_order' => 50,
            'referral_points_per_currency' => 1,
        ])->save();

        // A merchant may want a flat thank-you AND a rate AND a share; letting
        // one silently override another would be a setting that lies.
        // 50 flat + (200 × 1) + (5% of 200 → 100) = 350.
        $this->assertSame(350, $settings->refresh()->referralRewardFor(200.0));
    }

    public function test_a_percentage_alone_is_enough_to_make_the_program_live(): void
    {
        $settings = $this->settings(percent: 5, redeemPoints: 100, redeemAmount: 10.0);
        $settings->forceFill([
            'referral_enabled' => true,
            'referral_discount_value' => 0,
            'referral_points_per_order' => 0,
            'referral_points_per_currency' => 0,
        ])->save();

        // Before this setting existed, a merchant offering only a share would
        // have had a share button that rewarded nobody.
        $this->assertTrue($settings->refresh()->referralActive());
    }

    public function test_a_typo_cannot_hand_back_more_than_the_order(): void
    {
        $settings = $this->settings(percent: 500, redeemPoints: 100, redeemAmount: 10.0);

        $this->assertSame((float) MerchantLoyaltySettings::MAX_REFERRER_PERCENT, $settings->referralReferrerPercent());
    }

    public function test_a_negative_percentage_is_zero_not_a_deduction(): void
    {
        $settings = $this->settings(percent: -20, redeemPoints: 100, redeemAmount: 10.0);

        $this->assertSame(0.0, $settings->referralReferrerPercent());
        $this->assertSame(0, $settings->referralRewardFor(200.0));
    }

    public function test_no_order_value_means_no_share(): void
    {
        $settings = $this->settings(percent: 5, redeemPoints: 100, redeemAmount: 10.0);

        // A free order referred is still a referral, but a share of nothing is
        // nothing — and must not become a rounding-driven free point.
        $this->assertSame(0, $settings->referralRewardFor(0.0));
    }

    private function settings(float $percent, int $redeemPoints, float $redeemAmount, int $pointsPerCurrency = 1): MerchantLoyaltySettings
    {
        $settings = MerchantLoyaltySettings::current();
        $settings->forceFill([
            'points_per_currency' => $pointsPerCurrency,
            'redeem_rate_points' => $redeemPoints,
            'redeem_rate_amount' => $redeemAmount,
            'referral_referrer_percent' => $percent,
        ])->save();

        return $settings->refresh();
    }
}
