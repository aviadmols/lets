<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Jobs\Privacy\RedactCustomerData;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The birthday gift (once a year, on the day) and what a GDPR erasure does to a
 * membership: the person disappears, the accounting does not.
 */
final class LoyaltyBirthdayAndPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'birthday.myshopify.com',
            'name' => 'Birthday',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'birthday_points' => 100,
            'join_bonus_points' => 0,
        ])->save();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_gift_lands_on_the_day_and_only_once_a_year(): void
    {
        $account = app(PointsEngine::class)->join('42', 'dana@example.com');
        $account->forceFill([
            'birthday' => now()->subYears(30)->toDateString(), // same month + day as today
            'birthday_set_at' => now(),
        ])->save();

        $this->artisan('loyalty:grant-birthday-points')->assertSuccessful();
        $this->assertSame(100, (int) $account->refresh()->points_balance);

        // A re-run the same day (retried scheduler, redeploy) grants nothing more.
        $this->artisan('loyalty:grant-birthday-points')->assertSuccessful();
        $this->assertSame(100, (int) $account->refresh()->points_balance);
        $this->assertSame(1, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_BIRTHDAY)->count());
    }

    public function test_a_birthday_on_another_day_is_not_paid(): void
    {
        $account = app(PointsEngine::class)->join('42');
        $account->forceFill([
            'birthday' => now()->addDays(3)->subYears(25)->toDateString(),
            'birthday_set_at' => now(),
        ])->save();

        $this->artisan('loyalty:grant-birthday-points')->assertSuccessful();

        $this->assertSame(0, (int) $account->refresh()->points_balance);
    }

    public function test_a_disabled_club_grants_no_birthday_points(): void
    {
        $account = app(PointsEngine::class)->join('42');
        $account->forceFill([
            'birthday' => now()->subYears(30)->toDateString(),
            'birthday_set_at' => now(),
        ])->save();
        MerchantLoyaltySettings::current()->forceFill(['enabled' => false])->save();

        $this->artisan('loyalty:grant-birthday-points')->assertSuccessful();

        $this->assertSame(0, (int) $account->refresh()->points_balance);
    }

    public function test_erasure_clears_the_person_but_keeps_the_accounting(): void
    {
        $account = app(PointsEngine::class)->join('42', 'dana@example.com', 'Dana');
        $account->forceFill([
            'points_balance' => 500,
            'birthday' => '1990-05-04',
            'birthday_set_at' => now(),
        ])->save();

        // The real Shopify customers/redact payload shape.
        (new RedactCustomerData((int) $this->shop->getKey(), [
            'customer' => ['id' => '42', 'email' => 'dana@example.com'],
        ]))->handle();

        $account = LoyaltyAccount::query()->first();
        $this->assertNotSame('Dana', $account->customer_name);
        $this->assertNotSame('dana@example.com', $account->customer_email);
        $this->assertNull($account->birthday, 'The birthday is personal data and goes.');

        // The balance is the merchant's liability; erasing the person does not
        // erase what they are owed. And the once-only birthday guard survives, so
        // a redacted membership cannot re-enter a date to farm the annual gift.
        $this->assertSame(500, (int) $account->points_balance);
        $this->assertNotNull($account->birthday_set_at);
    }
}
