<?php

namespace Tests\Feature\Customers;

use App\Domain\Customers\CustomerPlans;
use App\Filament\Pages\Customers;
use App\Filament\Search\TenantGlobalSearchProvider;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One person, one row — however their subscriptions were keyed.
 *
 * A member imported with a UUID reference (since cancelled) re-subscribed at
 * checkout as a guest (`0`): keyed one field per plan, the list and the search
 * showed them twice, with the same email.
 */
final class CustomersMergedByEmailTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'merge.example.com',
            'name' => 'Merge',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_imported_member_who_came_back_as_a_guest_is_one_customer(): void
    {
        $old = $this->plan('e08b39e3-35fe-40e4-b2d8-17b09d953960', 'Yehuda@example.com', PlanStatus::CANCELLED, '+972548197876');
        $new = $this->plan('0', 'yehuda@example.com', PlanStatus::ACTIVE, '0548197876');
        $this->charge($old, 59);
        $this->charge($new, 79);
        // A one-time order of theirs: no plan, found by the email.
        $this->charge(null, 238, 'yehuda@example.com');

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertCount(1, $rows);
        $this->assertSame('e08b39e3-35fe-40e4-b2d8-17b09d953960', $rows->first()['id']);
        $this->assertSame(1, $rows->first()['active_subs']);
        $this->assertStringContainsString('376', $rows->first()['spend']);
        // The page it opens holds both subscriptions.
        $this->assertCount(2, CustomerPlans::query($rows->first()['id'])->get());
    }

    public function test_a_store_account_and_a_guest_checkout_with_its_email_are_one_customer_keyed_by_the_account(): void
    {
        $this->plan('0', 'dana@example.com', PlanStatus::ACTIVE);
        $this->plan('24', 'dana@example.com', PlanStatus::CANCELLED);

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertCount(1, $rows);
        // The account number, so the contact card can read and edit it.
        $this->assertSame('24', $rows->first()['id']);
    }

    public function test_guests_with_different_emails_stay_apart(): void
    {
        $this->plan('0', 'a@example.com', PlanStatus::ACTIVE);
        $this->plan('0', 'b@example.com', PlanStatus::ACTIVE);

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertEqualsCanonicalizing(['a@example.com', 'b@example.com'], $rows->pluck('id')->all());
    }

    public function test_a_shared_reference_links_two_emails_and_a_guest_on_either(): void
    {
        $this->plan('55', 'one@example.com', PlanStatus::ACTIVE);
        $this->plan('55', 'two@example.com', PlanStatus::ACTIVE);
        $this->plan('0', 'two@example.com', PlanStatus::ACTIVE);

        $this->assertSame(['55'], Livewire::test(Customers::class)->instance()->customers()->pluck('id')->all());
    }

    public function test_the_search_shows_the_person_once(): void
    {
        $this->plan('e08b39e3-35fe-40e4-b2d8-17b09d953960', 'yehuda@example.com', PlanStatus::CANCELLED);
        $this->plan('0', 'yehuda@example.com', PlanStatus::ACTIVE);

        $results = (new TenantGlobalSearchProvider)->getResults('yehuda');
        $customers = $results->getCategories()[__(TenantGlobalSearchProvider::GROUP_CUSTOMERS)];

        $this->assertCount(1, $customers);
    }

    private function plan(string $ref, string $email, PlanStatus $status, ?string $phone = null): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_name' => 'יהודה',
            'customer_email' => $email,
            'customer_phone' => $phone,
            'shopify_customer_id' => $ref,
            'external_customer_id' => $ref,
        ]);
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'status' => $status->value,
        ])->save();

        return $plan;
    }

    private function charge(?InstallmentPlan $plan, float $amount, ?string $email = null): void
    {
        (new PaymentLedger)->forceFill([
            'shop_id' => $this->shop->getKey(),
            'plan_id' => $plan?->getKey(),
            'charge_context' => 'recurring',
            'idempotency_key' => (string) Str::ulid(),
            'amount' => $amount,
            'status' => PaymentLedger::STATUS_SUCCEEDED,
            'customer_email' => $email,
        ])->save();
    }
}
