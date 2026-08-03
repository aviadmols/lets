<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The members page as a shopper meets it.
 *
 * The page is public HTML on the merchant's own domain, so the test that
 * matters most is the negative one: an unidentified visitor must see the pitch
 * and nothing else — no balance, no name, no membership — and every action must
 * refuse them outright.
 */
final class LoyaltyCustomerPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'club.example.com',
            'name' => 'Club',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'points_per_currency' => 1,
            'join_bonus_points' => 50,
            'birthday_points' => 100,
            'social_actions' => [
                ['key' => 'facebook_like', 'label' => 'Like us', 'points' => 25, 'url' => 'https://facebook.com/lets'],
            ],
        ])->save();

        $this->tier('Spark', 0, '#7746ec');
        $this->tier('Glow', 1000, '#10b981');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_url_is_refused(): void
    {
        // The signature IS the credential — without it there is no page.
        $this->get('/loyalty/'.$this->shop->getKey().'?ref=42')->assertForbidden();
    }

    public function test_a_tampered_reference_breaks_the_signature(): void
    {
        $url = $this->signedPage('42');

        // Swapping the customer reference invalidates the whole signature, so a
        // shopper cannot read another shopper's balance by editing the URL.
        $this->get(str_replace('ref=42', 'ref=99', $url))->assertForbidden();
    }

    public function test_a_member_sees_their_balance_and_tier(): void
    {
        app(PointsEngine::class)->join('42', 'dana@example.com', 'Dana');

        $this->get($this->signedPage('42'))
            ->assertOk()
            ->assertSee('50')            // the welcome bonus
            ->assertSee('Spark')         // their tier
            ->assertSee(__('loyalty.page.balance'));
    }

    public function test_a_non_member_sees_the_pitch_and_no_balance(): void
    {
        // Someone else's membership must not leak into a stranger's page.
        app(PointsEngine::class)->join('99', 'other@example.com', 'Other Person');

        $this->get($this->signedPage('42'))
            ->assertOk()
            ->assertSee(__('loyalty.page.join_cta'))
            ->assertSee('Spark')                       // the public pitch still shows
            ->assertDontSee('Other Person')
            ->assertDontSee(__('loyalty.page.balance'));
    }

    public function test_joining_from_the_page_creates_the_membership(): void
    {
        $this->postJson($this->signedAction('join', '42'))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(50, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_a_social_claim_pays_once(): void
    {
        app(PointsEngine::class)->join('42');

        $this->postJson($this->signedAction('social', '42'), ['key' => 'facebook_like'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // The second claim is refused by the idempotency wall, not by a flag.
        $this->postJson($this->signedAction('social', '42'), ['key' => 'facebook_like'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertSame(50 + 25, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_the_birthday_can_be_set_once(): void
    {
        app(PointsEngine::class)->join('42');

        $this->postJson($this->signedAction('birthday', '42'), ['birthday' => '1990-05-04'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Moving it would make the annual gift a repeatable payout.
        $this->postJson($this->signedAction('birthday', '42'), ['birthday' => '1991-01-01'])
            ->assertStatus(409);

        $this->assertSame('1990-05-04', LoyaltyAccount::query()->first()->birthday->toDateString());
    }

    public function test_a_future_birthday_is_refused(): void
    {
        app(PointsEngine::class)->join('42');

        $this->postJson($this->signedAction('birthday', '42'), ['birthday' => now()->addYear()->toDateString()])
            ->assertStatus(422);
    }

    public function test_a_disabled_program_serves_no_page(): void
    {
        MerchantLoyaltySettings::current()->forceFill(['enabled' => false])->save();

        $this->get($this->signedPage('42'))->assertNotFound();
    }

    // === Helpers ===

    private function signedPage(string $ref): string
    {
        return URL::temporarySignedRoute('loyalty.signed.page', now()->addHour(), [
            'shop' => (int) $this->shop->getKey(),
            'ref' => $ref,
            'email' => '',
            'name' => '',
        ]);
    }

    private function signedAction(string $action, string $ref): string
    {
        return URL::signedRoute('loyalty.signed.'.$action, [
            'shop' => (int) $this->shop->getKey(),
            'ref' => $ref,
            'email' => '',
            'name' => '',
        ]);
    }

    private function tier(string $name, float $minSpend, string $color): void
    {
        $tier = new LoyaltyTier;
        $tier->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => $name,
            'color' => $color,
            'icon' => LoyaltyTier::ICON_SPARK,
            'min_spend' => $minSpend,
            'points_multiplier' => 1,
            'entry_bonus_points' => 0,
            'perks' => ['A perk for '.$name],
            'position' => 0,
        ])->save();
    }
}
