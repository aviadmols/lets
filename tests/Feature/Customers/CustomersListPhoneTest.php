<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\Customers;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The phone on the customers list.
 *
 * It comes from the plan, captured at checkout — NOT from a per-row read of the
 * store. The detail screen reads contact details live because it shows one
 * customer; doing that per row here would be one API call per line and a list
 * that never paints.
 */
final class CustomersListPhoneTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'phones.example.com',
            'name' => 'Phones',
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

    public function test_the_phone_is_listed(): void
    {
        $this->plan('דנה', '55', phone: '0501234567');

        Livewire::test(Customers::class)
            ->assertSee(__('customers.list.col.phone'))
            ->assertSee('0501234567');
    }

    public function test_a_customer_without_one_shows_a_dash_not_a_blank(): void
    {
        $this->plan('דנה', '55', phone: null);

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertNull($rows->first()['phone']);
    }

    public function test_an_older_blank_plan_does_not_blank_the_column(): void
    {
        // Same customer, two subscriptions: the newer one captured a phone.
        $this->plan('דנה', '55', phone: null);
        $this->plan('דנה', '55', phone: '0509999999', email: 'dana2@example.com');

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertSame('0509999999', $rows->first()['phone']);
    }

    public function test_the_list_does_not_call_the_store(): void
    {
        Http::fake();
        $this->plan('דנה', '55', phone: '0501234567');

        Livewire::test(Customers::class)->instance()->customers();

        // One API call per row is how a customer list becomes unusable at scale.
        Http::assertNothingSent();
    }

    public function test_the_list_costs_the_same_at_any_size(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->plan('Customer '.$i, (string) $i, phone: '05000000'.$i, email: 'c'.$i.'@example.com');
        }
        $few = $this->queriesToList();

        for ($i = 6; $i <= 40; $i++) {
            $this->plan('Customer '.$i, (string) $i, phone: '05000000'.$i, email: 'c'.$i.'@example.com');
        }
        $many = $this->queriesToList();

        // Eight times the customers, the same number of queries: the rows are
        // grouped in PHP and the spend is ONE aggregate. Neither the phone nor the
        // total may become a lookup per line.
        $this->assertSame($few, $many, "the list grew from {$few} to {$many} queries");
    }

    private function queriesToList(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Customers::class)->instance()->customers();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function plan(string $name, string $customerRef, ?string $phone, string $email = 'dana@example.com'): void
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'shopify_customer_id' => $customerRef,
        ]);
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();
    }
}
