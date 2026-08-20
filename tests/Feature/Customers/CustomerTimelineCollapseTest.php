<?php

namespace Tests\Feature\Customers;

use App\Models\ActivityEvent;
use App\Support\Tenant;
use App\Support\Ui\EventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * One act, one row.
 *
 * A switch is written on BOTH plans — the one that ended and the one that took
 * over — which is right for each plan's own timeline. A CUSTOMER's timeline
 * aggregates every plan they hold, so one switch arrived twice and read as two:
 * a shopper who switched once produced four rows, and the merchant asked why
 * their subscription had been created and cancelled so many times. Nothing had.
 */
final class CustomerTimelineCollapseTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_second_copy_of_a_switch_is_collapsed(): void
    {
        $shop = $this->makeShop('collapse-switch.example.com');
        $details = ['offer_id' => '1', 'from_plan' => 'PLN-A', 'to_plan' => 'PLN-B'];

        [$first, $second] = Tenant::run($shop, fn (): array => [
            $this->event('plan_switched', $details, planId: 2),
            $this->event('plan_switched', $details, planId: 1),
        ]);

        $collapsed = EventPresenter::collapseTwoSided([$first, $second]);

        $this->assertCount(1, $collapsed);
        // The FIRST one survives: ordering stays the caller's decision.
        $this->assertSame(2, $collapsed[0]->plan_id);
    }

    public function test_two_different_switches_both_survive(): void
    {
        $shop = $this->makeShop('collapse-two.example.com');

        $events = Tenant::run($shop, fn (): array => [
            $this->event('plan_switched', ['from_plan' => 'PLN-A', 'to_plan' => 'PLN-B'], 1),
            $this->event('plan_switched', ['from_plan' => 'PLN-B', 'to_plan' => 'PLN-C'], 2),
        ]);

        $this->assertCount(2, EventPresenter::collapseTwoSided($events));
    }

    /**
     * The dangerous case this must never do: two DIFFERENT plans charged the same
     * amount in the same second carry identical details. Collapsing those would
     * hide real money — which is why only the two-sided kinds collapse at all.
     */
    public function test_identical_charges_on_two_plans_are_never_collapsed(): void
    {
        $shop = $this->makeShop('collapse-money.example.com');
        $details = ['amount' => 1, 'currency' => 'ILS'];

        $events = Tenant::run($shop, fn (): array => [
            $this->event('charge_succeeded', $details, 1),
            $this->event('charge_succeeded', $details, 2),
        ]);

        $this->assertCount(2, EventPresenter::collapseTwoSided($events));
    }

    public function test_a_single_plans_own_feed_is_left_alone(): void
    {
        $shop = $this->makeShop('collapse-single.example.com');

        $events = Tenant::run($shop, fn (): array => [
            $this->event('recurring_plan_created', ['amount' => 1], 1),
            $this->event('charge_succeeded', ['amount' => 1], 1),
            $this->event('plan_switched', ['from_plan' => 'A'], 1),
        ]);

        $this->assertCount(3, EventPresenter::collapseTwoSided($events));
    }

    /** @param array<string, mixed> $details */
    private function event(string $kind, array $details, int $planId): ActivityEvent
    {
        $event = new ActivityEvent;
        $event->forceFill([
            'shop_id' => Tenant::id(),
            'kind' => $kind,
            'details' => $details,
            'plan_id' => $planId,
            'actor' => ActivityEvent::ACTOR_SYSTEM,
            // Pinned: the two copies of one act share the same second, and that
            // is half of what identifies them.
            'created_at' => Carbon::parse('2026-08-20 09:24:19'),
        ])->save();

        return $event->fresh();
    }
}
