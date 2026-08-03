<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Domain\Loyalty\Referral\ReferralDiscountPublisher;
use App\Domain\Loyalty\Referral\ReferralService;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyReferral;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Friend brings a friend".
 *
 * The design choice under test: the referral code is a REAL discount code in the
 * store, so attribution rides the order rather than a cookie. What has to hold
 * is that one order pays the referrer exactly once, that a member cannot refer
 * themselves, and that a code the platform refused is never handed out.
 */
final class LoyaltyReferralTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'referral.myshopify.com',
            'name' => 'Referral',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'join_bonus_points' => 0,
            'referral_enabled' => true,
            'referral_discount_type' => MerchantLoyaltySettings::REFERRAL_PERCENT,
            'referral_discount_value' => 10,
            'referral_points_per_order' => 200,
        ])->save();

        // The store always accepts the code, unless a test says otherwise.
        $this->fakePublisher(true);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_member_gets_a_code_and_a_platform_link(): void
    {
        $account = app(PointsEngine::class)->join('42', 'dana@example.com');

        $code = app(ReferralService::class)->codeFor($this->shop, $account);

        $this->assertNotNull($code);
        $this->assertStringStartsWith('REF', $code);
        // The link is Shopify's OWN apply-discount URL — no JavaScript of ours
        // stands between the click and the code being in the cart.
        $this->assertSame(
            'https://referral.myshopify.com/discount/'.$code,
            app(ReferralService::class)->linkFor($this->shop, $code),
        );
        $this->assertNotNull($account->refresh()->referral_synced_at);
    }

    public function test_the_code_is_minted_once_and_reused(): void
    {
        $account = app(PointsEngine::class)->join('42');
        $service = app(ReferralService::class);

        $first = $service->codeFor($this->shop, $account);
        $second = $service->codeFor($this->shop, $account->refresh());

        $this->assertSame($first, $second);
    }

    public function test_a_refused_code_is_not_offered(): void
    {
        // The platform would reject it (missing scope, say). Handing the member
        // a code checkout will not honour is worse than offering nothing.
        $this->fakePublisher(false);
        $account = app(PointsEngine::class)->join('42');

        $this->assertNull(app(ReferralService::class)->codeFor($this->shop, $account));
        $this->assertNull($account->refresh()->referral_synced_at);
    }

    public function test_a_friends_purchase_pays_the_referrer_once(): void
    {
        [$referrer, $code] = $this->memberWithCode('42');

        $service = app(ReferralService::class);
        $service->attribute($this->shop, [$code], 'order-1', 300.0, '99', 'friend@example.com');
        // A redelivered webhook must not pay again.
        $service->attribute($this->shop, [$code], 'order-1', 300.0, '99', 'friend@example.com');

        $this->assertSame(1, LoyaltyReferral::query()->count());
        $this->assertSame(200, (int) $referrer->refresh()->points_balance);
        $this->assertSame(1, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_REFERRAL)->count());
    }

    public function test_points_can_scale_with_the_order(): void
    {
        MerchantLoyaltySettings::current()->forceFill([
            'referral_points_per_order' => 0,
            'referral_points_per_currency' => 2,
        ])->save();
        [$referrer, $code] = $this->memberWithCode('42');

        app(ReferralService::class)->attribute($this->shop, [$code], 'order-2', 150.0, '99', 'friend@example.com');

        $this->assertSame(300, (int) $referrer->refresh()->points_balance); // 150 × 2
    }

    public function test_a_member_cannot_refer_themselves(): void
    {
        [$referrer, $code] = $this->memberWithCode('42', 'dana@example.com');

        // Same customer reference…
        app(ReferralService::class)->attribute($this->shop, [$code], 'order-3', 300.0, '42', null);
        // …and the same person arriving under their email instead.
        app(ReferralService::class)->attribute($this->shop, [$code], 'order-4', 300.0, null, 'dana@example.com');

        $this->assertSame(0, LoyaltyReferral::query()->count());
        $this->assertSame(0, (int) $referrer->refresh()->points_balance);
    }

    public function test_an_unknown_code_credits_nobody(): void
    {
        $this->memberWithCode('42');

        app(ReferralService::class)->attribute($this->shop, ['SUMMERSALE'], 'order-5', 300.0, '99', null);

        $this->assertSame(0, LoyaltyReferral::query()->count());
    }

    public function test_the_code_matches_case_insensitively(): void
    {
        // Codes get read off a phone and typed in lowercase.
        [$referrer, $code] = $this->memberWithCode('42');

        app(ReferralService::class)->attribute($this->shop, [strtolower($code)], 'order-6', 100.0, '99', null);

        $this->assertSame(1, LoyaltyReferral::query()->count());
        $this->assertSame(200, (int) $referrer->refresh()->points_balance);
    }

    public function test_a_disabled_program_neither_offers_nor_credits(): void
    {
        [$referrer, $code] = $this->memberWithCode('42');
        MerchantLoyaltySettings::current()->forceFill(['referral_enabled' => false])->save();

        $this->assertNull(app(ReferralService::class)->codeFor($this->shop, $referrer));
        app(ReferralService::class)->attribute($this->shop, [$code], 'order-7', 300.0, '99', null);

        $this->assertSame(0, LoyaltyReferral::query()->count());
    }

    public function test_a_program_that_rewards_nobody_is_not_active(): void
    {
        // No discount for the friend AND no points for the member: a share
        // button that promises nothing should not exist.
        MerchantLoyaltySettings::current()->forceFill([
            'referral_discount_value' => 0,
            'referral_points_per_order' => 0,
            'referral_points_per_currency' => 0,
        ])->save();

        $this->assertFalse(MerchantLoyaltySettings::current()->refresh()->referralActive());
    }

    public function test_the_referrer_stats_read_back(): void
    {
        [$referrer, $code] = $this->memberWithCode('42');
        $service = app(ReferralService::class);

        $service->attribute($this->shop, [$code], 'order-8', 100.0, '98', null);
        $service->attribute($this->shop, [$code], 'order-9', 100.0, '97', null);

        $this->assertSame(['count' => 2, 'points' => 400], $service->statsFor($referrer->refresh()));
    }

    // === Fixtures ===

    /** @return array{0: LoyaltyAccount, 1: string} */
    private function memberWithCode(string $ref, ?string $email = null): array
    {
        $account = app(PointsEngine::class)->join($ref, $email);
        $code = app(ReferralService::class)->codeFor($this->shop, $account);

        return [$account->refresh(), (string) $code];
    }

    /** Swap the store publisher for one whose answer this test dictates. */
    private function fakePublisher(bool $accepts): void
    {
        $this->app->bind(ReferralDiscountPublisher::class, fn () => new class($accepts) extends ReferralDiscountPublisher
        {
            public function __construct(private bool $accepts) {}

            public function publish(Shop $shop, string $code, MerchantLoyaltySettings $settings): bool
            {
                return $this->accepts;
            }
        });
    }
}
