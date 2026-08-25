<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyAccount;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `loyalty:backfill-contacts` — giving proxy-rail members their face back.
 *
 * Members who joined through the Shopify proxy page hold only a numeric ref;
 * their purchases carry an email/name in the accrual event meta. The command
 * folds the newest one onto the account, and never touches a value the
 * merchant already set.
 */
final class LoyaltyContactBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'backfill.myshopify.com',
            'name' => 'Backfill',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'join_bonus_points' => 0,
            'points_per_currency' => 1,
        ])->save();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_newest_accrual_meta_wins(): void
    {
        $account = app(PointsEngine::class)->join('42');
        $this->accrue($account->customer_ref, 'old@example.com', 'Old Name', 'order-1');
        $this->accrue($account->customer_ref, 'new@example.com', 'New Name', 'order-2');
        // Enrichment-at-accrual already fills on the fly now, so blank the row
        // to simulate the pre-fix backlog the command exists for.
        $account->refresh()->forceFill(['customer_email' => null, 'customer_name' => null])->save();

        $this->artisan('loyalty:backfill-contacts', ['--shop' => $this->shop->getKey()])
            ->assertSuccessful();

        $account = $account->fresh();
        $this->assertSame('new@example.com', $account->customer_email);
        $this->assertSame('New Name', $account->customer_name);
    }

    public function test_existing_values_are_never_overwritten(): void
    {
        $account = app(PointsEngine::class)->join('43', 'merchant@example.com', null);
        $this->accrue($account->customer_ref, 'order@example.com', 'Order Name', 'order-3');
        $account->refresh()->forceFill(['customer_name' => null])->save();

        $this->artisan('loyalty:backfill-contacts', ['--shop' => $this->shop->getKey()])
            ->assertSuccessful();

        $account = $account->fresh();
        $this->assertSame('merchant@example.com', $account->customer_email, 'The merchant-set email must survive.');
        $this->assertSame('Order Name', $account->customer_name, 'Only the hole is filled.');
    }

    public function test_a_member_with_no_meta_is_left_alone(): void
    {
        $account = app(PointsEngine::class)->join('44');

        $this->artisan('loyalty:backfill-contacts', ['--shop' => $this->shop->getKey()])
            ->assertSuccessful();

        $account = $account->fresh();
        $this->assertNull($account->customer_email);
        $this->assertNull($account->customer_name);
    }

    // === Fixtures ===

    private function accrue(string $ref, string $email, string $name, string $orderId): void
    {
        app(PointsEngine::class)->accrue(
            customerRef: $ref,
            amount: 10.0,
            idempotencyKey: 'shopify-order:'.$orderId,
            meta: ['email' => $email, 'name' => $name, 'context' => 'shopify_order', 'order_id' => $orderId],
        );
    }
}
