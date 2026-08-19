<?php

namespace Tests\Feature\Customers;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "View as customer" — the shopper's own personal area, inside the admin.
 *
 * Two things must hold. It shows the REAL person (their plans, not a sample),
 * and it can only ever show THIS shop's people: the route is the merchant's own
 * authenticated session, so a reference belonging to another tenant must 404
 * rather than render a stranger's subscriptions.
 *
 * It is also not a login — no session is minted for the shopper — and the page
 * renders inert, which the host document enforces with `preview: true`.
 */
final class ViewAsCustomerTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ROUTE = '/admin/account/preview';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('viewas.example.com');
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_renders_the_customers_own_subscriptions(): void
    {
        $this->plan($this->shop, '77', 'Dana Subscriber', 'dana@example.com');

        $response = $this->get(self::ROUTE.'?customer=77');

        $response->assertOk();
        $response->assertSee('Dana Subscriber', escape: false);
        // The real renderer, not a second copy of the markup.
        $response->assertSee('account/lets-account.js', escape: false);
        // Inert by construction: the host hands the renderer no endpoint.
        $response->assertSee('preview: true', escape: false);
    }

    public function test_another_shops_customer_is_never_rendered(): void
    {
        $other = $this->makeShop('stranger.example.com');
        $this->plan($other, '77', 'Someone Else', 'stranger@example.com');

        // Same reference, different tenant — the plan lookup is shop-scoped, so
        // there is nothing to show and the page must refuse rather than improvise.
        $this->get(self::ROUTE.'?customer=77')->assertNotFound();
    }

    public function test_a_reference_with_no_plans_is_not_an_empty_page(): void
    {
        $this->get(self::ROUTE.'?customer=does-not-exist')->assertNotFound();
    }

    /** With no customer at all it stays the appearance preview it always was. */
    public function test_without_a_customer_it_renders_the_sample(): void
    {
        $this->get(self::ROUTE)->assertOk();
    }

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
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
