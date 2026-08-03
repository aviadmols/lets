<?php

namespace Tests\Feature\Admin;

use App\Domain\Storefront\StorefrontElementsPresenter;
use App\Filament\Pages\StorefrontElements;
use App\Models\MerchantLoyaltySettings;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Storefront page tells the merchant what will actually render and how to
 * place it. The two things worth pinning: the Shopify deep link must carry the
 * real extension uuid AND the block handle (a wrong one opens an empty editor),
 * and the subscriptions card must admit when no plan is published — that silence
 * is exactly what makes a merchant think the app is broken.
 */
final class StorefrontElementsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_shopify_cards_carry_a_theme_editor_deep_link_per_block(): void
    {
        $shop = $this->shopifyShop();
        Tenant::set($shop);

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');
        $uuid = (string) config('shopify.theme_extension_uuid');

        $this->assertStringContainsString('storefront.myshopify.com/admin/themes/current/editor', $elements['subscriptions']['deep_link']);
        $this->assertStringContainsString($uuid.'/'.StorefrontElementsPresenter::BLOCK_SUBSCRIPTIONS, $elements['subscriptions']['deep_link']);
        $this->assertStringContainsString($uuid.'/'.StorefrontElementsPresenter::BLOCK_DEPOSIT, $elements['deposit']['deep_link']);
        // The Liquid fallback for themes without app-block support.
        $this->assertStringContainsString('lets-installments-button', (string) $elements['deposit']['snippet']);
    }

    public function test_the_subscriptions_card_admits_when_no_plan_is_published(): void
    {
        $shop = $this->shopifyShop();
        Tenant::set($shop);

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');
        $this->assertSame(StorefrontElementsPresenter::STATUS_NEEDS_SETUP, $elements['subscriptions']['status']);

        $this->publishPlan($shop);

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');
        $this->assertSame(StorefrontElementsPresenter::STATUS_READY, $elements['subscriptions']['status']);
    }

    public function test_woo_cards_say_automatic_and_offer_no_deep_link(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        $this->publishPlan($shop);

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');

        // WooCommerce has no theme editor — a deep link there would be a lie.
        $this->assertNull($elements['deposit']['deep_link']);
        $this->assertSame(StorefrontElementsPresenter::STATUS_AUTO, $elements['deposit']['status']);
        $this->assertSame(StorefrontElementsPresenter::STATUS_AUTO, $elements['subscriptions']['status']);
    }

    public function test_the_loyalty_card_appears_only_once_the_program_is_enabled(): void
    {
        $shop = $this->shopifyShop();
        Tenant::set($shop);

        $keys = collect(app(StorefrontElementsPresenter::class)->elements($shop))->pluck('key');
        $this->assertNotContains(StorefrontElementsPresenter::ELEMENT_LOYALTY, $keys);

        MerchantLoyaltySettings::current()->forceFill(['enabled' => true])->save();

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');
        $this->assertArrayHasKey('loyalty', $elements->all());
        $this->assertStringContainsString('/apps/payplus/loyalty', $elements['loyalty']['page_url']);
    }

    public function test_the_loyalty_card_links_to_the_menu_editor_not_the_theme_editor(): void
    {
        $shop = $this->shopifyShop();
        Tenant::set($shop);
        MerchantLoyaltySettings::current()->forceFill(['enabled' => true])->save();

        $loyalty = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key')['loyalty'];

        // The App Proxy already serves the page on the merchant's own domain, so
        // there is no block to add. Sending them to the theme editor would have
        // them hunting for something that does not exist.
        $this->assertStringEndsWith('/admin/menus', (string) $loyalty['deep_link']);
        $this->assertSame(StorefrontElementsPresenter::LINK_MENUS, $loyalty['deep_link_kind']);
        $this->assertSame(5, $loyalty['steps']);
    }

    public function test_the_woo_customer_area_card_needs_no_placement(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');

        // The plugin adds its own My Account tab; the merchant's job is to
        // configure it, not to place it.
        $this->assertArrayHasKey(StorefrontElementsPresenter::ELEMENT_ACCOUNT, $elements->all());
        $this->assertSame(StorefrontElementsPresenter::STATUS_AUTO, $elements['account']['status']);
        $this->assertNull($elements['account']['shortcode']);
        $this->assertNull($elements['account']['deep_link']);
    }

    public function test_the_customer_area_card_is_not_offered_on_shopify_yet(): void
    {
        $shop = $this->shopifyShop();
        Tenant::set($shop);

        $keys = collect(app(StorefrontElementsPresenter::class)->elements($shop))->pluck('key');

        // The Shopify storefront surface is not built. Listing it as "coming"
        // would be a promise this screen has no business making.
        $this->assertNotContains(StorefrontElementsPresenter::ELEMENT_ACCOUNT, $keys);
    }

    public function test_the_woo_loyalty_card_offers_a_shortcode(): void
    {
        $shop = $this->wooShop();
        Tenant::set($shop);
        MerchantLoyaltySettings::current()->forceFill(['enabled' => true])->save();

        $elements = collect(app(StorefrontElementsPresenter::class)->elements($shop))->keyBy('key');

        $this->assertSame('[lets_loyalty]', $elements['loyalty']['shortcode']);
        $this->assertNull($elements['loyalty']['page_url']);
    }

    public function test_the_page_renders_for_a_bound_merchant(): void
    {
        $shop = $this->shopifyShop();
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        Livewire::test(StorefrontElements::class)
            ->assertOk()
            ->assertSee(__('storefront_admin.element.subscriptions.title'))
            ->assertSee(__('storefront_admin.element.deposit.title'));
    }

    // === Fixtures ===

    private function shopifyShop(): Shop
    {
        return Shop::create([
            'shopify_domain' => 'storefront.myshopify.com',
            'name' => 'Storefront',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
    }

    private function wooShop(): Shop
    {
        return Shop::create([
            'woocommerce_domain' => 'storefront.example.com',
            'name' => 'Storefront Woo',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function publishPlan(Shop $shop): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $shop->getKey(),
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => '8001',
            'title' => 'Coffee',
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        $plan = new ProductSubscriptionPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'product_id' => $product->getKey(),
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'plan_kind' => 'recurring',
            'interval_count' => 1,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => 0,
            'status' => PlanTemplateStatus::ACTIVE->value,
            'position' => 0,
        ])->save();
    }
}
