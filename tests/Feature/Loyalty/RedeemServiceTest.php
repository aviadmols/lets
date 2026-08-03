<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\Credit\CreditIssuer;
use App\Domain\Loyalty\Credit\WooCouponIssuer;
use App\Domain\Loyalty\PointsEngine;
use App\Domain\Loyalty\RedeemService;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Redemption's one rule: the platform issues the credit FIRST, and points leave
 * only after it has. Everything here is a way of checking that a failure costs
 * the customer nothing.
 */
final class RedeemServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'redeem.example.com',
            'name' => 'Redeem',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $this->shop->woocommerce_credentials = [
            'base_url' => 'https://redeem.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $this->shop->save();
        Tenant::set($this->shop->fresh());

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'redeem_rate_points' => 100,
            'redeem_rate_amount' => 10.00,
            'min_redeem_points' => 0,
            'join_bonus_points' => 0,
        ])->save();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_successful_redemption_deducts_only_whole_chunks(): void
    {
        $account = $this->memberWith(250);
        $this->fakeIssuer(fn (): ?string => 'LETS-ABC123');

        $result = app(RedeemService::class)->redeem($this->shop, $account, 'ILS');

        $this->assertTrue($result['ok']);
        $this->assertSame(20.0, $result['amount']);   // 2 chunks of 100 points
        $this->assertSame('LETS-ABC123', $result['code']);
        // The leftover 50 points stay as points rather than rounding away.
        $this->assertSame(50, (int) $account->refresh()->points_balance);
    }

    public function test_an_issuer_failure_leaves_the_balance_untouched(): void
    {
        $account = $this->memberWith(300);
        $this->fakeIssuer(fn () => throw new \RuntimeException('loyalty.credit.transport'));

        $result = app(RedeemService::class)->redeem($this->shop, $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_FAILED, $result['reason']);
        $this->assertSame(300, (int) $account->refresh()->points_balance, 'A failed issue must cost the customer nothing.');
        $this->assertSame(0, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_REDEEM)->count());
    }

    public function test_a_balance_below_the_minimum_is_refused(): void
    {
        MerchantLoyaltySettings::current()->forceFill(['min_redeem_points' => 500])->save();
        $account = $this->memberWith(300);
        $this->fakeIssuer(fn (): ?string => 'SHOULD-NOT-BE-CALLED');

        $result = app(RedeemService::class)->redeem($this->shop, $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_BELOW_MINIMUM, $result['reason']);
        $this->assertSame(300, (int) $account->refresh()->points_balance);
    }

    public function test_a_balance_under_one_chunk_has_nothing_to_redeem(): void
    {
        $account = $this->memberWith(40);
        $this->fakeIssuer(fn (): ?string => null);

        $result = app(RedeemService::class)->redeem($this->shop, $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_NOTHING_TO_REDEEM, $result['reason']);
    }

    public function test_a_display_only_program_cannot_redeem(): void
    {
        // Rate amount 0 = "points are a scoreboard for now".
        MerchantLoyaltySettings::current()->forceFill(['redeem_rate_amount' => 0])->save();
        $account = $this->memberWith(1000);

        $result = app(RedeemService::class)->redeem($this->shop, $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_DISABLED, $result['reason']);
    }

    public function test_the_woo_issuer_refuses_a_member_with_no_email(): void
    {
        // Without an email the coupon cannot be locked to one person, and an
        // unrestricted credit code is a hole.
        $account = $this->memberWith(200, email: null);

        $this->expectException(\RuntimeException::class);
        app(WooCouponIssuer::class)->issue($this->shop, $account, 20.0, 'ILS');
    }

    // === Helpers ===

    private function memberWith(int $points, ?string $email = 'dana@example.com'): LoyaltyAccount
    {
        $account = app(PointsEngine::class)->join('42', $email, 'Dana');
        $account->forceFill(['points_balance' => $points, 'lifetime_points' => $points])->save();

        return $account->refresh();
    }

    /** Swap the platform issuer for one whose outcome this test dictates. */
    private function fakeIssuer(\Closure $issue): void
    {
        $this->app->bind(WooCouponIssuer::class, fn (): CreditIssuer => new class($issue) implements CreditIssuer
        {
            public function __construct(private \Closure $issue) {}

            public function issue(Shop $shop, LoyaltyAccount $account, float $amount, string $currency): ?string
            {
                return ($this->issue)($amount);
            }

            public function available(Shop $shop): bool
            {
                return true;
            }
        });
    }
}
