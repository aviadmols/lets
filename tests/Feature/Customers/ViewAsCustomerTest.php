<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\CustomerDetail;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "View as customer" — the READ-ONLY window, on every platform.
 *
 * What must hold: the window shows exactly the customer named in the URL and
 * nobody else (tenant wall + ref wall), every control is inert (`preview: true`
 * and no endpoint), and each open leaves an audit trace under its OWN kind —
 * looking is not becoming, and the Timeline must keep the two apart.
 */
final class ViewAsCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_action_is_offered_on_a_shopify_shop(): void
    {
        $shop = $this->shopifyShop('viewas-offered.myshopify.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());
        $this->plan($shop, '77', 'Dana Subscriber', 'dana@example.com');

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->assertActionVisible('viewAsCustomer')
            // The real login-as stays a WooCommerce capability.
            ->assertActionHidden('loginAsCustomer');
    }

    public function test_the_window_shows_the_customers_real_plans_read_only(): void
    {
        $shop = $this->shopifyShop('viewas-window.myshopify.com');
        Tenant::set($shop);
        $admin = User::factory()->forShop($shop)->create();
        $this->actingAs($admin);

        $plan = $this->plan($shop, '77', 'Dana Subscriber', 'dana@example.com');
        $this->plan($shop, '88', 'Yossi Other', 'yossi@example.com');

        $response = $this->get(route('filament.admin.account.view_as', ['customer' => '77']))
            ->assertOk()
            ->assertSee('preview: true', escape: false)
            ->assertSee((string) $plan->public_id);

        // The OTHER shopper's plan must not ride along.
        $response->assertDontSee('yossi@example.com');

        // The open left its own kind on the customer's record — distinct from
        // impersonation, naming the admin.
        $event = Tenant::run($shop, fn (): ?ActivityEvent => ActivityEvent::query()
            ->where('kind', Timeline::KIND_CUSTOMER_VIEWED_AS)
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame('77', $event->details['customer'] ?? null);
        $this->assertSame('admin:'.$admin->getKey(), $event->actor);
    }

    public function test_an_admin_of_another_shop_cannot_view_this_customer(): void
    {
        $shopA = $this->shopifyShop('viewas-a.myshopify.com');
        $this->plan($shopA, '77', 'Dana Subscriber', 'dana@example.com');

        $shopB = $this->shopifyShop('viewas-b.myshopify.com');
        Tenant::set($shopB);
        $this->actingAs(User::factory()->forShop($shopB)->create());

        // Same ref, other tenant: the page renders EMPTY (the tenant scope is
        // the wall), never shop A's data.
        $this->get(route('filament.admin.account.view_as', ['customer' => '77']))
            ->assertOk()
            ->assertDontSee('dana@example.com');
    }

    public function test_a_guest_is_not_served_at_all(): void
    {
        $shop = $this->shopifyShop('viewas-guest.myshopify.com');
        $this->plan($shop, '77', 'Dana Subscriber', 'dana@example.com');

        $this->get('/admin/account/view-as/77')->assertRedirect();
    }

    // === helpers ===

    private function shopifyShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
    }

    private function plan(Shop $shop, string $customerId, string $name, string $email): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $customerId, $name, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'public_id' => 'PLN-viewas-'.uniqid('', true),
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'shopify_customer_id' => $customerId,
                'customer_name' => $name,
                'customer_email' => $email,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 59,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
            ])->save();

            return $plan;
        });
    }
}
