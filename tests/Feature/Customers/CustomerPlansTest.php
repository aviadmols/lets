<?php

namespace Tests\Feature\Customers;

use App\Domain\Customers\CustomerPlans;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who a customer is, across the columns the rails disagree about.
 *
 * The SQL SHAPE is asserted here, not only the result, and that is deliberate:
 * these tests run on sqlite, which compares a UUID to an integer column without
 * complaining, while production is Postgres, which aborts the whole query with
 * `invalid input syntax for type bigint`. A behavioural test therefore passes on
 * both a correct and a broken query — this is how the customer page came to 500
 * for every imported member (their reference is the UUID their old system gave
 * them) with a green suite behind it. Reading the query back is what closes that
 * gap without a Postgres container.
 */
final class CustomerPlansTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'plans.example.com',
            'name' => 'Plans',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /**
     * A UUID can never be a bigint, so the bigint column must not be asked.
     *
     * Matched as a QUOTED column ("customer_id"), because the bare name is also
     * a tail of shopify_customer_id and external_customer_id — a substring test
     * would pass on any query at all and prove nothing.
     */
    public function test_a_non_numeric_reference_never_touches_the_numeric_column(): void
    {
        $sql = CustomerPlans::query('2883bb96-31e5-444e-a4c2-679437e7d17a')->toSql();

        $this->assertStringNotContainsString(
            '"'.CustomerPlans::NUMERIC_REF_COLUMN.'"',
            $sql,
            'Postgres aborts the query rather than not matching — see the class note',
        );
        $this->assertStringContainsString('"shopify_customer_id"', $sql);
        $this->assertStringContainsString('"external_customer_id"', $sql);
    }

    /** A numeric reference still matches it — that is what the column is for. */
    public function test_a_numeric_reference_does_use_the_numeric_column(): void
    {
        $this->assertStringContainsString(
            '"'.CustomerPlans::NUMERIC_REF_COLUMN.'"',
            CustomerPlans::query('4172')->toSql(),
        );
    }

    /** The imported member the 500 was about: found, by the reference they carry. */
    public function test_an_imported_member_resolves_by_their_uuid(): void
    {
        $uuid = '2883bb96-31e5-444e-a4c2-679437e7d17a';

        $mine = $this->plan(['external_customer_id' => $uuid, 'customer_email' => 'motti@example.com']);
        // Same person, second plan, reachable only through the shared address.
        $alsoMine = $this->plan(['customer_email' => 'motti@example.com']);
        $stranger = $this->plan(['external_customer_id' => 'other-uuid', 'customer_email' => 'other@example.com']);

        $found = CustomerPlans::query($uuid)->pluck('id');

        $this->assertTrue($found->contains($mine->getKey()));
        $this->assertTrue($found->contains($alsoMine->getKey()), 'the email pulls in the rest');
        $this->assertFalse($found->contains($stranger->getKey()));
    }

    /** A blank reference is a bug upstream; "everyone" is never the answer. */
    public function test_an_empty_reference_matches_nobody(): void
    {
        $this->plan(['customer_email' => 'someone@example.com']);

        $this->assertCount(0, CustomerPlans::query('  ')->get());
    }

    /** @param array<string, mixed> $attributes */
    private function plan(array $attributes): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill(array_merge([
            'shop_id' => (int) $this->shop->getKey(),
            'public_id' => 'PLN-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
        ], $attributes))->save();

        return $plan;
    }
}
