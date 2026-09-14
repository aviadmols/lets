<?php

namespace Tests\Feature\Bulk;

use App\Domain\Bulk\SubscriptionCriteria;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHICH subscriptions a bulk edit is aimed at.
 *
 * This is the object the whole feature's safety rests on, because the same
 * instance is counted by the preview and walked by the worker. If it can mean two
 * things, a merchant confirms one number and a different set changes — so the
 * tests here are about it meaning exactly one thing: every field narrows, junk
 * falls out as "no constraint" rather than reaching the database, and with no
 * tenant bound it matches nothing at all.
 */
final class SubscriptionCriteriaTest extends TestCase
{
    use MakesBulkSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop();
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_kind_filter_narrows_to_one_plan_kind(): void
    {
        $recurring = $this->makePlan($this->shop, 'recurring');
        $installments = $this->makePlan($this->shop, 'installments', ['plan_kind' => PlanKind::INSTALLMENTS->value]);

        $ids = SubscriptionCriteria::fromArray(['plan_kind' => PlanKind::RECURRING->value])
            ->apply()->pluck('id')->all();

        $this->assertSame([$recurring->id], $ids);
        $this->assertNotContains($installments->id, $ids);
    }

    public function test_the_product_filter_matches_the_platform_id(): void
    {
        $coffee = $this->makePlan($this->shop, 'coffee');
        $this->makePlan($this->shop, 'tea', ['external_product_id' => self::PRODUCT_TEA]);

        $ids = SubscriptionCriteria::fromArray(['external_product_id' => self::PRODUCT_COFFEE])
            ->apply()->pluck('id')->all();

        $this->assertSame([$coffee->id], $ids);
    }

    public function test_the_frequency_and_interval_filters_combine(): void
    {
        $monthly = $this->makePlan($this->shop, 'monthly');
        $quarterly = $this->makePlan($this->shop, 'every-three', ['interval_count' => 3]);
        $this->makePlan($this->shop, 'yearly', ['billing_frequency' => BillingFrequency::YEARLY->value]);

        // The unit alone finds both monthly cadences…
        $this->assertCount(2, SubscriptionCriteria::fromArray([
            'billing_frequency' => BillingFrequency::MONTHLY->value,
        ])->apply()->get());

        // …and the interval separates "monthly" from "every 3 months".
        $this->assertSame(
            [$quarterly->id],
            SubscriptionCriteria::fromArray([
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 3,
            ])->apply()->pluck('id')->all(),
        );

        $this->assertSame(
            [$monthly->id],
            SubscriptionCriteria::fromArray([
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
            ])->apply()->pluck('id')->all(),
        );
    }

    /**
     * The merchant's own example: everybody who charges on one given day.
     *
     * Both ends of the range set to the same date is how "charge date X" is asked
     * for, and it must be a single day rather than an empty set.
     */
    public function test_one_day_is_expressed_as_both_ends_of_the_range(): void
    {
        $onTheThird = $this->makePlan($this->shop, 'third', ['next_charge_at' => '2026-10-03 00:00:00']);
        $this->makePlan($this->shop, 'fourth', ['next_charge_at' => '2026-10-04 00:00:00']);
        $this->makePlan($this->shop, 'never', ['next_charge_at' => null]);

        $ids = SubscriptionCriteria::fromArray([
            'next_charge_from' => '2026-10-03',
            'next_charge_until' => '2026-10-03',
        ])->apply()->pluck('id')->all();

        $this->assertSame([$onTheThird->id], $ids);
    }

    /**
     * A charge date recorded with a TIME still belongs to its day.
     *
     * Imported and orchestrator-advanced plans do not all sit at midnight, and a
     * range built with whereDate is the only version of this filter that finds
     * them. A naive `>= '2026-10-03'` comparison on a timestamp column would too,
     * but `<= '2026-10-03'` would silently exclude anything after midnight — which
     * is most of the book.
     */
    public function test_a_charge_date_with_a_time_is_still_inside_its_day(): void
    {
        $afternoon = $this->makePlan($this->shop, 'afternoon', ['next_charge_at' => '2026-10-03 14:37:00']);

        $ids = SubscriptionCriteria::fromArray([
            'next_charge_from' => '2026-10-03',
            'next_charge_until' => '2026-10-03',
        ])->apply()->pluck('id')->all();

        $this->assertSame([$afternoon->id], $ids);
    }

    /** "No next charge date" is its own question, and it overrides the range. */
    public function test_without_next_charge_overrides_the_date_range(): void
    {
        $stopped = $this->makePlan($this->shop, 'stopped', ['next_charge_at' => null]);
        $this->makePlan($this->shop, 'scheduled', ['next_charge_at' => '2026-10-03 00:00:00']);

        $ids = SubscriptionCriteria::fromArray([
            'without_next_charge' => true,
            // Contradictory on purpose: an AND of the two matches nothing, which on
            // a screen reads as a broken filter rather than a contradictory one.
            'next_charge_from' => '2026-10-03',
            'next_charge_until' => '2026-10-03',
        ])->apply()->pluck('id')->all();

        $this->assertSame([$stopped->id], $ids);
    }

    public function test_the_status_filter_takes_several_statuses(): void
    {
        $active = $this->makePlan($this->shop, 'active');
        $dunning = $this->makePlan($this->shop, 'dunning', ['status' => PlanStatus::AWAITING_PAYMENT->value]);
        $cancelled = $this->makePlan($this->shop, 'cancelled', ['status' => PlanStatus::CANCELLED->value]);

        $ids = SubscriptionCriteria::fromArray([
            'statuses' => [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_PAYMENT->value],
        ])->apply()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$active->id, $dunning->id], $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    /** The search box's identity fallback: found by email when there is no name. */
    public function test_the_search_matches_any_identity_field(): void
    {
        $nameless = $this->makePlan($this->shop, 'nameless', [
            'customer_name' => null,
            'customer_email' => 'findme@example.com',
        ]);
        $this->makePlan($this->shop, 'other');

        $ids = SubscriptionCriteria::fromArray(['search' => 'findme'])->apply()->pluck('id')->all();

        $this->assertSame([$nameless->id], $ids);
    }

    /**
     * Junk falls out as "no constraint" — never reaches the query.
     *
     * The danger is not a database error; it is a filter value nobody recognises
     * matching nothing, and the resulting count of zero being echoed back to a
     * merchant as an answer.
     */
    public function test_unknown_values_are_dropped_rather_than_queried(): void
    {
        $criteria = SubscriptionCriteria::fromArray([
            'plan_kind' => 'not-a-kind',
            'billing_frequency' => 'fortnightly-ish',
            'statuses' => ['active', 'made-up', 42],
            'interval_count' => -5,
            'next_charge_from' => 'the third of never',
        ]);

        $this->assertNull($criteria->planKind);
        $this->assertNull($criteria->billingFrequency);
        $this->assertSame([PlanStatus::ACTIVE->value], $criteria->statuses);
        $this->assertNull($criteria->intervalCount);
        $this->assertNull($criteria->nextChargeFrom);
    }

    public function test_it_round_trips_through_its_stored_shape(): void
    {
        $original = SubscriptionCriteria::fromArray([
            'plan_kind' => PlanKind::RECURRING->value,
            'statuses' => [PlanStatus::ACTIVE->value],
            'external_product_id' => self::PRODUCT_COFFEE,
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 3,
            'next_charge_from' => '2026-10-01',
            'next_charge_until' => '2026-10-31',
            'created_from' => '2026-01-01',
            'search' => 'dana',
        ]);

        $this->assertEquals(
            $original->toArray(),
            SubscriptionCriteria::fromArray($original->toArray())->toArray(),
            'a criteria stored on a run row must rebuild into the same target',
        );
    }

    public function test_an_unfiltered_criteria_knows_that_it_is_unfiltered(): void
    {
        $this->assertTrue(SubscriptionCriteria::fromArray([])->isUnfiltered());
        $this->assertFalse(SubscriptionCriteria::fromArray(['search' => 'dana'])->isUnfiltered());
    }

    /** RELEASE BLOCKER: another shop's subscriptions are not in the target set. */
    public function test_it_never_reaches_another_shops_subscriptions(): void
    {
        $mine = $this->makePlan($this->shop, 'mine');

        $other = $this->makeShop('other.example.com');
        Tenant::set($other);
        $theirs = $this->makePlan($other, 'theirs');

        Tenant::set($this->shop);

        $ids = SubscriptionCriteria::fromArray([])->apply()->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /** With NO tenant bound the target is EMPTY, never everything. */
    public function test_it_fails_closed_with_no_tenant_bound(): void
    {
        $this->makePlan($this->shop, 'mine');

        Tenant::clear();

        $this->assertSame(0, SubscriptionCriteria::fromArray([])->apply()->count());
        $this->assertSame(1, InstallmentPlan::acrossAllTenants()->count(), 'the row exists; the scope hid it');
    }
}
