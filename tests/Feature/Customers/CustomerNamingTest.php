<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Customers screen shows PEOPLE, not database keys.
 *
 * It grouped plans by shopify_customer_id and printed that id, so on a
 * WooCommerce store — where the id is a bare number — the list read "1". The name
 * was on the plan the whole time; checkout captured it.
 *
 * And the search box says "name or email" while searching only the id, which on
 * exactly those stores means typing a customer's name finds nobody.
 */
final class CustomerNamingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_customer_row_is_labelled_with_their_name(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '1', name: 'אביעד מולשצקי', email: 'aviad@example.com');

        $row = (new Customers())->customers()->firstOrFail();

        $this->assertSame('אביעד מולשצקי', $row['label']);
        $this->assertSame('aviad@example.com', $row['email']);
        // The id still identifies the row (it is what the detail link routes on).
        $this->assertSame('1', $row['id']);
    }

    public function test_a_customer_with_only_an_email_is_labelled_by_it(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '2', name: null, email: 'guest@example.com');

        $row = (new Customers())->customers()->firstOrFail();

        $this->assertSame('guest@example.com', $row['label']);
    }

    public function test_searching_by_name_finds_the_customer(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '1', name: 'Meir Sella', email: 'meir@example.com');
        $this->plan($shop, customerId: '2', name: 'Dana Buyer', email: 'dana@example.com');

        $page = new Customers();
        $page->search = 'Meir';

        $rows = $page->customers();

        $this->assertCount(1, $rows);
        $this->assertSame('Meir Sella', $rows->first()['label']);
    }

    public function test_searching_by_email_finds_the_customer(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '1', name: 'Meir Sella', email: 'meir@example.com');
        $this->plan($shop, customerId: '2', name: 'Dana Buyer', email: 'dana@example.com');

        $page = new Customers();
        $page->search = 'dana@';

        $this->assertSame('Dana Buyer', $page->customers()->firstOrFail()['label']);
    }

    public function test_the_detail_page_is_titled_with_the_name(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '1', name: 'Meir Sella', email: 'meir@example.com');

        $page = new CustomerDetail();
        $page->mount('1');

        $this->assertSame('Meir Sella', $page->displayName());
    }

    /**
     * The page RENDERS. Its subscription link passed `record` to a route whose
     * parameter is `plan`, so the URL could not be generated and the whole detail
     * page 500'd for any customer who had a plan — which is every customer it
     * exists to show. Asserting the view compiles is what catches that; asserting
     * the page class in isolation never would.
     */
    public function test_the_detail_page_renders_with_a_linked_plan(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '1', name: 'Meir Sella', email: 'meir@example.com');

        Livewire::test(CustomerDetail::class, ['customer' => '1'])
            ->assertOk()
            ->assertSee('Meir Sella')
            // The link resolves to the plan's own URL rather than blowing up.
            ->assertSee('/admin/subscriptions/', escape: false);
    }

    public function test_a_customer_with_no_captured_name_still_renders(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, customerId: '99', name: null, email: null);

        // Falling back to the id is the honest answer — better than an empty cell
        // that looks like a broken row.
        $this->assertSame('99', (new Customers())->customers()->firstOrFail()['label']);
    }

    // === Fixtures ===

    private function shop(): Shop
    {
        return Shop::create([
            'woocommerce_domain' => 'customers-naming.example.com',
            'name' => 'Customers Naming',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function plan(Shop $shop, string $customerId, ?string $name, ?string $email): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $customerId, $name, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'shopify_customer_id' => $customerId,
                'customer_name' => $name,
                'customer_email' => $email,
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            return $plan;
        });
    }
}
