<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Customers derive from BOTH rails. A Shopify-Payments store has no
 * installment_plans rows at all — its subscribers live in subscription_contracts
 * — and deriving the list from plans alone is why its Customers screen sat
 * completely empty while the store had live subscriptions.
 */
final class CustomersFromContractsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'contracts-cust.myshopify.com',
            'name' => 'Contracts',
            'status' => Shop::STATUS_INSTALLED,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_contract_only_store_lists_its_subscribers(): void
    {
        $this->contract('777', name: null, email: null);

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertCount(1, $rows);
        // Protected data pending ⇒ the row says "Customer #id", never a blank.
        $this->assertSame(__('shopify_subscriptions.detail.customer_ref', ['id' => '777']), $rows->first()['label']);
        $this->assertSame(1, $rows->first()['active_subs']);
        $this->assertSame('green', $rows->first()['dot']);
    }

    public function test_a_named_contract_customer_shows_the_name(): void
    {
        $this->contract('778', name: 'Tricia VanCleef', email: 'tricia@example.com');

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertSame('Tricia VanCleef', $rows->first()['label']);
        $this->assertSame('tricia@example.com', $rows->first()['email']);
    }

    public function test_a_customer_on_both_rails_merges_into_one_row(): void
    {
        $this->contract('779', name: null, email: null);
        $this->plan('779', name: 'דנה לוי');

        $rows = Livewire::test(Customers::class)->instance()->customers();

        $this->assertCount(1, $rows);
        // The plan captured the identity at checkout — it wins over "Customer #779".
        $this->assertSame('דנה לוי', $rows->first()['label']);
        // One active plan + one active contract.
        $this->assertSame(2, $rows->first()['active_subs']);
    }

    public function test_the_detail_page_lists_the_customers_contracts(): void
    {
        $contract = $this->contract('780', name: 'Noa', email: 'noa@example.com');

        $page = new CustomerDetail();
        $page->mount('780');

        $this->assertSame('Noa', $page->displayName());
        $this->assertTrue($page->contracts()->contains('id', $contract->getKey()));
        $this->assertSame(1, $page->activePlansCount());
    }

    // === Fixtures ===

    private function contract(string $customerId, ?string $name, ?string $email): SubscriptionContract
    {
        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => $this->shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/9'.$customerId,
            'shopify_customer_gid' => 'gid://shopify/Customer/'.$customerId,
            'customer_name' => $name,
            'customer_email' => $email,
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'amount' => 73.00,
            'currency' => 'ILS',
            'synced_at' => now(),
        ])->save();

        return $contract;
    }

    private function plan(string $customerId, string $name): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'shopify_customer_id' => $customerId,
            'customer_name' => $name,
            'total_amount' => 50,
            'total_charged' => 0,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'public_id' => 'PLAN-'.uniqid(),
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan;
    }
}
