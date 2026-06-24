<?php

namespace Tests\Feature\Platform;

use App\Filament\Resources\ShopResource\Pages\ListShops;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * W11 Phase 1 — the "Add WooCommerce store" action on the platform Shops list. A
 * platform admin enters a domain and the store is created with a minted connection
 * token held only for the reveal modal.
 */
final class WooCommerceOnboardingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_add_a_woocommerce_store(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create());

        $component = Livewire::test(ListShops::class)
            ->callAction('addWooCommerce', data: [
                'domain' => 'https://liveaction.example.com/',
                'name' => 'Live Action',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('shops', [
            'woocommerce_domain' => 'liveaction.example.com',
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'status' => Shop::STATUS_INSTALLED,
        ]);

        // The connection token is held in memory for the reveal modal (shown once).
        $shop = Shop::query()->where('woocommerce_domain', 'liveaction.example.com')->first();
        $this->assertNotNull($shop?->lets_api_key_hash);
        $this->assertNotNull($component->get('wcConnection'));
    }

    public function test_a_merchant_cannot_access_the_shops_list(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'm.myshopify.com',
            'name' => 'M',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->getKey()]));

        Livewire::test(ListShops::class)->assertForbidden();
    }
}
