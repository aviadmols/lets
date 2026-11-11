<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\ManageBillingSettings;
use App\Models\MerchantCheckoutSettings;
use App\Models\Shop;
use App\Models\User;
use App\Services\PayPlus\PayPlusPageOptions;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Billing → Payment page: the merchant picks the PayPlus page's
 * language from the LETS dashboard. It writes the SAME column the WooCommerce
 * plugin's "Page language" field writes, so every page the shop produces
 * follows it.
 */
final class PaymentPageLanguageTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const DOMAIN = 'page-language.example.com';

    private function shop(): Shop
    {
        return Shop::create([
            'woocommerce_domain' => self::DOMAIN,
            'name' => self::DOMAIN,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_screen_switches_the_payment_page_to_english(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ManageBillingSettings::class)
                ->assertSet('data.payment_page_language', MerchantCheckoutSettings::DEFAULT_LANGUAGE)
                ->set('data.payment_page_language', 'en')
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame('en', MerchantCheckoutSettings::current()->fresh()->languageCode());
        });

        $this->assertSame('en', app(PayPlusPageOptions::class)->for($shop)['language_code']);
    }

    public function test_an_unknown_language_never_reaches_the_row(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ManageBillingSettings::class)
                ->set('data.payment_page_language', 'zz')
                ->call('save');

            $this->assertSame(MerchantCheckoutSettings::DEFAULT_LANGUAGE, MerchantCheckoutSettings::current()->fresh()->language_code);
        });
    }
}
