<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Domain\Loyalty\TierResolver;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The points engine's two invariants: every cause mints exactly once (the
 * idempotency wall), and the balance is only ever moved under a lock by this
 * class. Plus the product rule that makes the club a club — no membership, no
 * points, however much the shopper spends.
 */
final class PointsEngineTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'points.myshopify.com',
            'name' => 'Points',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'points_per_currency' => 1,
            'join_bonus_points' => 50,
        ])->save();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_non_member_earns_nothing(): void
    {
        // The opt-in wall: spending without joining creates no account and no points.
        $event = $this->engine()->accrue('cust-1', 500.0, 'earn:ledger:1');

        $this->assertNull($event);
        $this->assertSame(0, LoyaltyAccount::query()->count());
    }

    public function test_joining_grants_the_welcome_bonus_once(): void
    {
        $engine = $this->engine();
        $account = $engine->join('cust-1', 'a@example.com', 'Dana');

        $this->assertSame(50, (int) $account->points_balance);

        // A second join must not pay again.
        $again = $engine->join('cust-1', 'a@example.com', 'Dana');
        $this->assertSame(50, (int) $again->points_balance);
        $this->assertSame(1, LoyaltyAccount::query()->count());
    }

    public function test_the_same_cause_mints_exactly_once(): void
    {
        $engine = $this->engine();
        $engine->join('cust-1');

        $first = $engine->accrue('cust-1', 120.0, 'earn:ledger:7', 7);
        $second = $engine->accrue('cust-1', 120.0, 'earn:ledger:7', 7);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A replayed cause must collapse onto the first event.');
        $this->assertSame(50 + 120, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_the_tier_multiplier_applies_and_the_entry_bonus_pays_once(): void
    {
        $this->tier('Spark', minSpend: 0, multiplier: 1.0, entryBonus: 0);
        $this->tier('Glow', minSpend: 1000, multiplier: 2.0, entryBonus: 300);

        $engine = $this->engine();
        $engine->join('cust-1');

        // Below the threshold: ×1. Spend 1000 → 1000 points, then ascend to Glow.
        $engine->accrue('cust-1', 1000.0, 'earn:ledger:1', 1);
        $account = LoyaltyAccount::query()->first();
        $this->assertSame('Glow', $account->tier->name);
        $this->assertSame(50 + 1000 + 300, (int) $account->points_balance, 'Purchase earns at the OLD rate; the entry bonus is added on ascent.');

        // Now inside Glow: ×2.
        $engine->accrue('cust-1', 100.0, 'earn:ledger:2', 2);
        $this->assertSame(50 + 1000 + 300 + 200, (int) LoyaltyAccount::query()->first()->points_balance);

        // A third purchase must NOT re-pay the Glow entry bonus.
        $engine->accrue('cust-1', 50.0, 'earn:ledger:3', 3);
        $this->assertSame(1, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_TIER_ENTRY)->count());
    }

    public function test_the_tier_boundary_is_inclusive(): void
    {
        $this->tier('Spark', minSpend: 0, multiplier: 1.0, entryBonus: 0);
        $this->tier('Glow', minSpend: 1000, multiplier: 2.0, entryBonus: 0);

        $resolver = new TierResolver;
        $this->assertSame('Spark', $resolver->tierFor(999.99)->name);
        $this->assertSame('Glow', $resolver->tierFor(1000.0)->name, 'Exactly at the threshold is INSIDE the tier.');
    }

    public function test_a_negative_adjustment_cannot_overdraw_the_balance(): void
    {
        $engine = $this->engine();
        $account = $engine->join('cust-1'); // 50 points

        $engine->grant($account, LoyaltyPointEvent::KIND_ADJUST, -500, 'adjust:x');

        $this->assertSame(0, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    public function test_deducting_more_than_the_balance_throws(): void
    {
        $engine = $this->engine();
        $account = $engine->join('cust-1');

        $this->expectException(\RuntimeException::class);
        $engine->deduct($account, 5000, 'redeem:x');
    }

    public function test_a_disabled_program_accrues_nothing(): void
    {
        $engine = $this->engine();
        $engine->join('cust-1');
        MerchantLoyaltySettings::current()->forceFill(['enabled' => false])->save();

        $this->assertNull($engine->accrue('cust-1', 100.0, 'earn:ledger:9', 9));
    }

    public function test_a_member_is_found_by_email_when_the_reference_differs(): void
    {
        // One human, two rails: joined as a WooCommerce user id, later charged
        // under their email. Matching on email keeps one balance, not two.
        $engine = $this->engine();
        $engine->join('42', 'dana@example.com');

        $engine->accrue('dana@example.com', 200.0, 'earn:ledger:5', 5, ['email' => 'dana@example.com']);

        $this->assertSame(1, LoyaltyAccount::query()->count());
        $this->assertSame(50 + 200, (int) LoyaltyAccount::query()->first()->points_balance);
    }

    // === Fixtures ===

    private function engine(): PointsEngine
    {
        return new PointsEngine(new TierResolver);
    }

    private function tier(string $name, float $minSpend, float $multiplier, int $entryBonus): LoyaltyTier
    {
        $tier = new LoyaltyTier;
        $tier->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => $name,
            'color' => '#7746ec',
            'icon' => LoyaltyTier::ICON_SPARK,
            'min_spend' => $minSpend,
            'points_multiplier' => $multiplier,
            'entry_bonus_points' => $entryBonus,
            'position' => 0,
        ])->save();

        return $tier;
    }
}
