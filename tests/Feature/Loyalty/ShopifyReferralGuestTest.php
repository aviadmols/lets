<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Domain\Loyalty\Referral\ReferralDiscountPublisher;
use App\Domain\Loyalty\Referral\ReferralService;
use App\Listeners\Loyalty\AccruePointsFromShopifyOrder;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyReferral;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Shopify order-paid listener, seen from the referral side.
 *
 * The rule under test: a GUEST checkout has nobody to give points to, but the
 * member whose code rode in on the order still earned their share — the buyer's
 * identity wall must sit AFTER the referral, not before it. (That was a real
 * bug: the listener used to return on the missing customer id first, silently
 * eating every referred guest purchase.)
 */
final class ShopifyReferralGuestTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'guests.myshopify.com',
            'name' => 'Guests',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'join_bonus_points' => 0,
            'points_per_currency' => 1,
            'referral_enabled' => true,
            'referral_discount_type' => MerchantLoyaltySettings::REFERRAL_PERCENT,
            'referral_discount_value' => 10,
            'referral_points_per_order' => 200,
        ])->save();

        // The store always accepts the published code in these tests.
        $this->app->bind(ReferralDiscountPublisher::class, fn () => new class extends ReferralDiscountPublisher
        {
            public function __construct() {}

            public function publish(Shop $shop, string $code, MerchantLoyaltySettings $settings): bool
            {
                return true;
            }
        });

        // The listener re-enters the tenant itself from shop_id.
        Tenant::clear();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_guest_purchase_still_pays_the_referrer(): void
    {
        [$referrer, $code] = $this->memberWithCode('42');

        $this->deliver([
            'customer' => [], // guest checkout: no customer record at all
            'email' => 'guest@example.com',
            'total_price' => '300.00',
            'discount_codes' => [['code' => $code]],
        ]);

        $this->inTenant(function () use ($referrer): void {
            $referral = LoyaltyReferral::query()->sole();
            $this->assertSame('guest@example.com', $referral->buyer_email);
            $this->assertNull($referral->buyer_ref);
            $this->assertSame(200, (int) $referrer->refresh()->points_balance);

            // …but the guest themselves earned nothing: no identity, no account.
            $this->assertSame(
                0,
                LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_EARN_PURCHASE)->count(),
                'A guest has no member account to credit.',
            );
        });
    }

    public function test_a_guest_buying_with_their_own_code_is_not_a_referral(): void
    {
        [, $code] = $this->memberWithCode('42', 'dana@example.com');

        $this->deliver([
            'customer' => [],
            'email' => 'Dana@Example.com', // the member, checked out as a guest
            'total_price' => '300.00',
            'discount_codes' => [['code' => $code]],
        ]);

        $this->inTenant(function (): void {
            $this->assertSame(0, LoyaltyReferral::query()->count());
        });
    }

    public function test_an_order_with_no_identity_at_all_credits_nobody(): void
    {
        // A phone-only / POS checkout: no customer record AND no email. The
        // self-referral wall cannot run, so attribution must refuse — otherwise
        // a member could farm their own code anonymously.
        [, $code] = $this->memberWithCode('42', 'dana@example.com');

        $this->deliver([
            'customer' => [],
            'total_price' => '300.00',
            'discount_codes' => [['code' => $code]],
        ], orderId: 'order-anonymous');

        $this->inTenant(function (): void {
            $this->assertSame(0, LoyaltyReferral::query()->count());
        });
    }

    public function test_a_member_purchase_enriches_a_faceless_account(): void
    {
        // Joined through the proxy page: a bare numeric ref, no email, no name.
        $this->inTenant(fn () => app(PointsEngine::class)->join('77'));

        $this->deliver([
            'customer' => ['id' => 77, 'email' => 'noa@example.com', 'first_name' => 'Noa', 'last_name' => 'Levi'],
            'total_price' => '100.00',
            'discount_codes' => [],
        ], orderId: 'order-enrich');

        $this->inTenant(function (): void {
            $account = LoyaltyAccount::query()->where('customer_ref', '77')->sole();
            $this->assertSame('noa@example.com', $account->customer_email);
            $this->assertSame('Noa Levi', $account->customer_name);
            $this->assertSame(100, (int) $account->points_balance);
        });
    }

    public function test_enrichment_never_overwrites_what_is_already_there(): void
    {
        $this->inTenant(fn () => app(PointsEngine::class)->join('88', 'kept@example.com', 'Kept Name'));

        $this->deliver([
            'customer' => ['id' => 88, 'email' => 'other@example.com', 'first_name' => 'Other'],
            'total_price' => '50.00',
        ], orderId: 'order-keep');

        $this->inTenant(function (): void {
            $account = LoyaltyAccount::query()->where('customer_ref', '88')->sole();
            $this->assertSame('kept@example.com', $account->customer_email);
            $this->assertSame('Kept Name', $account->customer_name);
        });
    }

    // === Fixtures ===

    /** Run the webhook listener the way the dispatcher does. */
    private function deliver(array $order, string $orderId = 'order-guest-1'): void
    {
        (new AccruePointsFromShopifyOrder)->handle([
            'shop_id' => $this->shop->getKey(),
            'order_id' => $orderId,
            'payload' => $order,
        ]);
    }

    /** @return array{0: LoyaltyAccount, 1: string} */
    private function memberWithCode(string $ref, ?string $email = null): array
    {
        return $this->inTenant(function () use ($ref, $email): array {
            $account = app(PointsEngine::class)->join($ref, $email, $email !== null ? 'Dana' : null);
            $code = app(ReferralService::class)->codeFor($this->shop, $account);

            return [$account->refresh(), (string) $code];
        });
    }

    /** The assertions read tenant-scoped tables, so they enter the tenant briefly. */
    private function inTenant(\Closure $callback): mixed
    {
        return Tenant::run($this->shop, $callback);
    }
}
