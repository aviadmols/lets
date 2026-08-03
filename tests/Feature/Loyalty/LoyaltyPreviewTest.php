<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\Rendering\LoyaltyPagePresenter;
use App\Filament\Pages\ManageLoyalty;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin's preview of the members page.
 *
 * It renders the REAL page so what the merchant tunes cannot drift from what
 * shoppers get — which makes the important tests the ones about what a preview
 * must NOT do: create a membership, mint a referral code in the merchant's
 * store, or be readable by someone who is not signed in.
 */
final class LoyaltyPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'preview.myshopify.com',
            'name' => 'Preview',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'program_name' => 'מועדון הבדיקה',
            'points_per_currency' => 1,
            'redeem_rate_points' => 100,
            'redeem_rate_amount' => 10,
        ])->save();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_signed_out_visitor_cannot_read_the_preview(): void
    {
        $this->get(route('filament.admin.loyalty.preview'))->assertRedirect();
    }

    public function test_the_preview_renders_the_real_page_with_a_sample_member(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $this->get(route('filament.admin.loyalty.preview'))
            ->assertOk()
            ->assertSee('מועדון הבדיקה')                    // the merchant's own club name
            ->assertSee(__('loyalty.page.balance'))          // rendered as a member
            ->assertSee('loyalty.css', escape: false);       // the REAL stylesheet
    }

    public function test_the_preview_creates_no_membership_and_mints_no_code(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $this->get(route('filament.admin.loyalty.preview'))->assertOk();

        // Opening a design tab must not touch the club's data or publish a
        // discount code to the merchant's store.
        $this->assertSame(0, LoyaltyAccount::query()->count());
    }

    public function test_a_shop_with_no_tiers_still_previews_a_ladder(): void
    {
        // A merchant should see the shape before they build one.
        $model = app(LoyaltyPagePresenter::class)->sample(MerchantLoyaltySettings::current());

        $this->assertCount(3, $model['tiers']);
        $this->assertTrue($model['tiers'][0]['is_current']);
        $this->assertNotEmpty($model['perks']['rows']);
    }

    public function test_the_preview_uses_the_merchants_real_tiers_when_they_exist(): void
    {
        $this->tier('Bronze', 0);
        $this->tier('Silver', 500);

        $model = app(LoyaltyPagePresenter::class)->sample(MerchantLoyaltySettings::current());

        $this->assertSame(['Bronze', 'Silver'], array_column($model['tiers'], 'name'));
    }

    public function test_the_draft_appearance_is_guarded_before_it_reaches_the_preview(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $page = Livewire::test(ManageLoyalty::class)
            ->set('data.accent_color', 'not-a-colour')
            ->set('data.corner_radius', 'pill')
            ->set('data.page_locale', 'he');

        $draft = $page->instance()->draftAppearance();

        // A half-typed colour previews as the house default, not as a broken page.
        $this->assertSame(MerchantLoyaltySettings::DEFAULT_ACCENT, $draft['accent']);
        $this->assertSame('999px', $draft['radius']);
        $this->assertSame('rtl', $draft['dir']);
    }

    private function tier(string $name, float $minSpend): void
    {
        $tier = new LoyaltyTier;
        $tier->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => $name,
            'color' => '#7746ec',
            'icon' => LoyaltyTier::ICON_SPARK,
            'min_spend' => $minSpend,
            'points_multiplier' => 1,
            'entry_bonus_points' => 0,
            'perks' => ['A perk'],
            'position' => 0,
        ])->save();
    }
}
