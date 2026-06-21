<?php

namespace Tests\Feature\Upsell;

use App\Filament\Pages\PostPurchaseOffers;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: the Post-Purchase Offers hub must render for a freshly-installed,
 * bound merchant with ZERO upsell data (the state a brand-new shop like themefree
 * is in). A {"message":""} error on /admin/post-purchase-offers pointed here.
 */
final class PostPurchaseOffersRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_renders_for_a_freshly_installed_merchant_with_no_data(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'themefree.myshopify.com',
            'name' => 'themefree',
            'status' => Shop::STATUS_INSTALLED,
            'shopify_access_token' => 'shpat_x',
        ]);
        $user = User::create([
            'name' => 'Merchant',
            'email' => 'merchant@themefree.test',
            'password' => bcrypt('secret-x'),
            'shop_id' => $shop->id,
        ]);

        Tenant::set($shop);
        $this->actingAs($user);

        // Mounting + rendering runs mount(), overviewKpis(), revenueChart(),
        // flows(), activityEvents() — any throw fails the test with the cause.
        Livewire::test(PostPurchaseOffers::class)
            ->assertOk()
            ->assertSet('tab', 'overview');
    }
}
