<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Filament\Pages\LoyaltyMembers;
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
 * The loyalty admin. What matters here is what the merchant CANNOT do by typing
 * the wrong thing: mint an absurd rate, break a member's tier link by renaming a
 * rung, or edit a balance without leaving a record.
 */
final class LoyaltyAdminTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'loyalty-admin.myshopify.com',
            'name' => 'Loyalty Admin',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_program_saves(): void
    {
        Livewire::test(ManageLoyalty::class)
            ->set('data.enabled', true)
            ->set('data.program_name', 'מועדון החברים')
            ->set('data.points_per_currency', 2)
            ->set('data.redeem_rate_points', 100)
            ->set('data.redeem_rate_amount', 10)
            ->set('data.join_bonus_points', 50)
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = MerchantLoyaltySettings::current()->refresh();
        $this->assertTrue((bool) $settings->enabled);
        $this->assertSame(2, $settings->pointsPerCurrency());
        $this->assertSame(50, $settings->joinBonusPoints());
    }

    public function test_out_of_range_rates_are_refused_at_the_form(): void
    {
        // The first wall is the form: a rate of zero points would divide the
        // whole redemption by nothing, so the merchant is told, not clamped
        // silently into a policy they did not choose.
        Livewire::test(ManageLoyalty::class)
            ->set('data.points_per_currency', 99999)
            ->set('data.redeem_rate_points', 0)
            ->call('save')
            ->assertHasFormErrors(['points_per_currency', 'redeem_rate_points']);

        $this->assertFalse((bool) MerchantLoyaltySettings::current()->refresh()->enabled);
    }

    public function test_stored_garbage_is_still_clamped_on_read(): void
    {
        // Defence in depth behind the form: whatever ends up in the row (an old
        // migration, a console edit), the model refuses to hand out a value the
        // program could not honour.
        MerchantLoyaltySettings::current()->forceFill([
            'points_per_currency' => 99999,
            'redeem_rate_points' => 0,
            'join_bonus_points' => -5,
            'theme_mode' => 'neon',
            'accent_color' => 'not-a-colour',
        ])->save();

        $settings = MerchantLoyaltySettings::current()->refresh();
        $this->assertSame(MerchantLoyaltySettings::MAX_POINTS_PER_CURRENCY, $settings->pointsPerCurrency());
        $this->assertSame(MerchantLoyaltySettings::MIN_REDEEM_RATE_POINTS, $settings->redeemRatePoints());
        $this->assertSame(0, $settings->joinBonusPoints());
        $this->assertSame(MerchantLoyaltySettings::THEME_LIGHT, $settings->themeMode());
        $this->assertSame(MerchantLoyaltySettings::DEFAULT_ACCENT, $settings->accentColor());
    }

    public function test_saving_tiers_creates_updates_and_deletes_the_ladder(): void
    {
        Livewire::test(ManageLoyalty::class)
            ->set('data.tiers', [
                ['name' => 'Spark', 'min_spend' => 0, 'points_multiplier' => 1, 'entry_bonus_points' => 0, 'icon' => 'spark', 'color' => '#7746ec', 'perks' => ['1 point per shekel']],
                ['name' => 'Glow', 'min_spend' => 1000, 'points_multiplier' => 1.25, 'entry_bonus_points' => 300, 'icon' => 'glow', 'color' => '#10b981', 'perks' => []],
            ])
            ->call('save');

        $this->assertSame(['Spark', 'Glow'], LoyaltyTier::query()->ordered()->pluck('name')->all());
        $glow = LoyaltyTier::query()->where('name', 'Glow')->first();
        $this->assertSame(300, $glow->entryBonusPoints());

        // Editing keeps the SAME row (so a member's tier_id still points at the
        // tier they earned) and removing one deletes only it.
        Livewire::test(ManageLoyalty::class)
            ->set('data.tiers', [
                ['id' => LoyaltyTier::query()->where('name', 'Spark')->value('id'), 'name' => 'Spark', 'min_spend' => 0, 'points_multiplier' => 1, 'entry_bonus_points' => 0, 'icon' => 'spark', 'color' => '#7746ec', 'perks' => []],
            ])
            ->call('save');

        $this->assertSame(1, LoyaltyTier::query()->count());
        $this->assertSame('Spark', LoyaltyTier::query()->first()->name);
    }

    public function test_an_unnamed_tier_is_dropped(): void
    {
        Livewire::test(ManageLoyalty::class)
            ->set('data.tiers', [
                ['name' => '', 'min_spend' => 500, 'points_multiplier' => 2, 'entry_bonus_points' => 0, 'icon' => 'star', 'color' => '#7746ec', 'perks' => []],
            ])
            ->call('save');

        $this->assertSame(0, LoyaltyTier::query()->count());
    }

    public function test_social_actions_save_and_survive_the_round_trip(): void
    {
        Livewire::test(ManageLoyalty::class)
            ->set('data.social_actions', [
                ['key' => 'facebook_like', 'label' => 'Like us', 'points' => 25, 'url' => 'https://facebook.com/lets'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $actions = MerchantLoyaltySettings::current()->refresh()->socialActions();
        $this->assertCount(1, $actions);
        $this->assertSame('facebook_like', $actions[0]['key']);
        $this->assertSame(25, $actions[0]['points']);
    }

    public function test_a_stored_action_worth_nothing_is_dropped_on_read(): void
    {
        MerchantLoyaltySettings::current()->forceFill([
            'social_actions' => [
                ['key' => 'facebook_like', 'label' => 'Like us', 'points' => 25, 'url' => 'https://facebook.com/lets'],
                ['key' => 'instagram_follow', 'label' => 'Follow', 'points' => 0, 'url' => 'https://instagram.com/lets'],
            ],
        ])->save();

        // An action worth zero points is not an action — it would render as a
        // button that thanks the customer for nothing.
        $this->assertCount(1, MerchantLoyaltySettings::current()->refresh()->socialActions());
    }

    public function test_a_non_https_social_url_is_dropped_on_read(): void
    {
        MerchantLoyaltySettings::current()->forceFill([
            'social_actions' => [['key' => 'custom', 'label' => 'X', 'points' => 10, 'url' => 'javascript:alert(1)']],
        ])->save();

        // The guard runs on READ too — a hostile scheme never reaches a page.
        $this->assertNull(MerchantLoyaltySettings::current()->refresh()->socialActions()[0]['url']);
    }

    public function test_a_manual_adjustment_is_recorded_as_an_event(): void
    {
        MerchantLoyaltySettings::current()->forceFill(['enabled' => true])->save();
        $account = app(PointsEngine::class)->join('cust-1', 'dana@example.com', 'Dana');

        Livewire::test(LoyaltyMembers::class)
            ->assertSee('Dana')
            ->callAction('adjustPoints', ['points' => 120, 'reason' => 'apology'], ['account' => $account->getKey()]);

        $account = LoyaltyAccount::query()->first();
        $this->assertSame(120, (int) $account->points_balance);
        $this->assertSame('apology', $account->events()->latest('id')->first()->meta['reason']);
    }
}
