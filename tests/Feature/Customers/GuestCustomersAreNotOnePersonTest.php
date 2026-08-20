<?php

namespace Tests\Feature\Customers;

use App\Domain\Customers\CustomerPlans;
use App\Models\InstallmentPlan;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * A guest checkout is not an identity, and every guest is not one customer.
 *
 * WooCommerce writes `0` for "no user", so EVERY guest in a shop carries the
 * same reference. Matching on it merged four different people — four different
 * addresses — into a single customer: the merchant opened one shopper's page
 * and found somebody else's subscriptions listed as theirs, which is both a
 * wrong screen and other people's purchase history.
 *
 * The rule already written for a blank email now covers the value that means
 * the same thing: a guest is reachable by the address they typed and by nothing
 * else.
 */
final class GuestCustomersAreNotOnePersonTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const GUEST_REF = '0';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_one_guests_page_never_shows_another_guests_subscriptions(): void
    {
        $shop = $this->makeShop('guests.example.com');

        Tenant::run($shop, function (): void {
            $this->guestPlan('first@example.com');
            $this->guestPlan('second@example.com');
            $this->guestPlan('second@example.com');
        });

        $found = Tenant::run($shop, fn () => CustomerPlans::query('second@example.com')->get());

        $this->assertCount(2, $found);
        $this->assertSame(
            ['second@example.com'],
            $found->pluck('customer_email')->unique()->values()->all(),
        );
    }

    /**
     * The reference itself resolves to NOBODY. Fail closed, exactly as an empty
     * reference does — this query decides whose purchase history is on screen.
     */
    public function test_the_guest_reference_itself_identifies_nobody(): void
    {
        $shop = $this->makeShop('guest-ref.example.com');

        Tenant::run($shop, function (): void {
            $this->guestPlan('first@example.com');
            $this->guestPlan('second@example.com');
        });

        $this->assertFalse(CustomerPlans::identifies(self::GUEST_REF));
        $this->assertSame(0, Tenant::run($shop, fn () => CustomerPlans::query(self::GUEST_REF)->count()));
        $this->assertSame([], Tenant::run($shop, fn () => CustomerPlans::emailsFor(self::GUEST_REF)));
    }

    /** A real reference still gathers everything that names it, as before. */
    public function test_a_real_reference_still_finds_every_plan_it_names(): void
    {
        $shop = $this->makeShop('real-ref.example.com');

        Tenant::run($shop, function (): void {
            $this->guestPlan('member@example.com', ref: '12');
            $this->guestPlan('member@example.com', ref: '12');
            $this->guestPlan('stranger@example.com');
        });

        $this->assertTrue(CustomerPlans::identifies('12'));
        $this->assertSame(2, Tenant::run($shop, fn () => CustomerPlans::query('12')->count()));
    }

    private function guestPlan(string $email, string $ref = self::GUEST_REF): InstallmentPlan
    {
        $plan = new InstallmentPlan([
            'public_id' => 'PLN-'.uniqid(),
            'plan_kind' => 'recurring',
            'charge_context' => 'recurring',
            'customer_id' => null,
            'shopify_customer_id' => $ref,
            'external_customer_id' => $ref,
            'customer_email' => $email,
            'customer_name' => 'Guest',
            'total_amount' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
        ]);
        $plan->forceFill(['shop_id' => Tenant::id(), 'status' => 'active'])->save();

        return $plan;
    }
}
