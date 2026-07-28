<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Filament\Resources\SubscriptionContractResource;
use App\Filament\Resources\SubscriptionResource;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONE subscriptions screen in the sidebar, whichever rail the shop is on.
 *
 * The two rails have two lists — installment_plans for PayPlus (we hold the
 * token and charge) and the mirrored contracts for Shopify Payments (Shopify
 * holds the card). Showing both gave the merchant two near-identically named
 * screens, one of which was empty by construction and always would be, which
 * reads as a broken app rather than as a rail that does not apply.
 *
 * The exception that keeps it honest: rows already there always win. A shop that
 * changed rails still has real money in the other list, and hiding the only
 * screen that shows it would hide that money.
 */
final class RailNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_shopify_rail_shop_sees_only_the_shopify_list(): void
    {
        Tenant::set($this->shop(Shop::RAIL_SHOPIFY_PAYMENTS));

        $this->assertTrue(SubscriptionContractResource::shouldRegisterNavigation());
        $this->assertFalse(
            SubscriptionResource::shouldRegisterNavigation(),
            'The PayPlus list is empty by construction here — showing it reads as a broken screen.',
        );
    }

    public function test_a_payplus_shop_sees_only_the_payplus_list(): void
    {
        Tenant::set($this->shop(Shop::RAIL_PAYPLUS));

        $this->assertTrue(SubscriptionResource::shouldRegisterNavigation());
        $this->assertFalse(SubscriptionContractResource::shouldRegisterNavigation());
    }

    public function test_existing_rows_keep_their_screen_after_a_rail_change(): void
    {
        $shop = $this->shop(Shop::RAIL_SHOPIFY_PAYMENTS);
        Tenant::set($shop);

        // Real PayPlus plans from before the switch. Hiding the only screen that
        // shows them would hide money the merchant still has.
        $plan = InstallmentPlan::create([
            'shop_id' => $shop->getKey(),
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'installment_amount' => 100,
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'currency' => 'ILS',
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save(); // status is guarded

        $this->assertTrue(SubscriptionResource::shouldRegisterNavigation());
    }

    public function test_a_payplus_shop_that_kept_mirrored_contracts_still_sees_them(): void
    {
        $shop = $this->shop(Shop::RAIL_PAYPLUS);
        Tenant::set($shop);

        SubscriptionContract::create([
            'shop_id' => $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/1',
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'currency' => 'ILS',
        ]);

        $this->assertTrue(SubscriptionContractResource::shouldRegisterNavigation());
    }

    private function shop(string $rail): Shop
    {
        return Shop::create([
            'shopify_domain' => 'rail-nav.myshopify.com',
            'name' => 'Rail Nav',
            'status' => Shop::STATUS_INSTALLED,
            'subscription_rail' => $rail,
        ]);
    }
}
