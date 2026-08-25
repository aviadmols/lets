<?php

namespace Tests\Feature\Loyalty;

use App\Filament\Resources\LoyaltyReferralResource;
use App\Filament\Resources\LoyaltyReferralResource\Pages\ListLoyaltyReferrals;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReferral;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merchant-facing referral report (Customers → Referrals).
 *
 * What must hold: the list shows a shop its OWN referral rows with the
 * referrer named the way the members list names them; another shop's admin
 * never sees a row across the tenant wall; and the screen is a LEDGER —
 * read-only, no create route — because a hand-written referral would be a
 * points payout with no order behind it.
 */
final class LoyaltyReferralResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_report_lists_a_referral_row_for_the_shops_admin(): void
    {
        $shop = $this->shop('referrals-list.myshopify.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $referral = $this->referral($shop, 'Dana Referrer', 'REFDANA1', 'friend@example.com', 'order-1001');

        Livewire::test(ListLoyaltyReferrals::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$referral])
            ->assertSee('Dana Referrer')
            ->assertSee('REFDANA1')
            ->assertSee('friend@example.com')
            ->assertSee('order-1001');
    }

    public function test_the_referrer_is_named_by_the_member_display_precedence(): void
    {
        $shop = $this->shop('referrals-label.myshopify.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        // No name on the account — the label falls back to the email, the
        // same precedence LoyaltyAccount::label() gives the members list.
        $this->referral($shop, null, 'REFMAIL1', 'buyer@example.com', 'order-2002', 'member@example.com');

        Livewire::test(ListLoyaltyReferrals::class)
            ->assertOk()
            ->assertSee('member@example.com');
    }

    public function test_another_shops_admin_never_sees_these_rows(): void
    {
        $shopA = $this->shop('referrals-a.myshopify.com');
        $referralA = $this->referral($shopA, 'Dana Referrer', 'REFDANA1', 'friend-a@example.com', 'order-3003');

        $shopB = $this->shop('referrals-b.myshopify.com');
        Tenant::set($shopB);
        $this->actingAs(User::factory()->forShop($shopB)->create());

        Livewire::test(ListLoyaltyReferrals::class)
            ->assertOk()
            ->assertCanNotSeeTableRecords([$referralA])
            ->assertDontSee('friend-a@example.com')
            ->assertDontSee('Dana Referrer');
    }

    public function test_the_resource_is_read_only(): void
    {
        // No create — rows are written by ReferralService on the webhook.
        $this->assertFalse(LoyaltyReferralResource::canCreate());

        // The index is the only page: no create/edit routes exist at all.
        $this->assertSame(['index'], array_keys(LoyaltyReferralResource::getPages()));

        $base = LoyaltyReferralResource::getRouteBaseName(panel: 'admin');
        $this->assertTrue(Route::has($base.'.index'));
        $this->assertFalse(Route::has($base.'.create'));
    }

    // === Fixtures ===

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
    }

    /** One member with a code, and one purchase their link brought in. */
    private function referral(
        Shop $shop,
        ?string $referrerName,
        string $code,
        string $buyerEmail,
        string $orderId,
        ?string $referrerEmail = null,
    ): LoyaltyReferral {
        return Tenant::run($shop, function () use ($shop, $referrerName, $code, $buyerEmail, $orderId, $referrerEmail): LoyaltyReferral {
            $account = new LoyaltyAccount;
            $account->forceFill([
                'shop_id' => $shop->getKey(),
                'customer_ref' => '42',
                'customer_name' => $referrerName,
                'customer_email' => $referrerEmail,
                'referral_code' => $code,
                'joined_at' => now(),
            ])->save();

            $referral = new LoyaltyReferral;
            $referral->forceFill([
                'shop_id' => $shop->getKey(),
                'referrer_account_id' => $account->getKey(),
                'buyer_ref' => '99',
                'buyer_email' => $buyerEmail,
                'external_order_id' => $orderId,
                'order_amount' => 250.00,
                'points_awarded' => 200,
            ])->save();

            return $referral;
        });
    }
}
