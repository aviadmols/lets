<?php

namespace Tests\Feature\Account;

use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A section a merchant's saved list predates must arrive switched ON.
 *
 * `sectionEnabled()` answers false for anything it cannot find, and every shop
 * that ever opened the sections screen has a saved list frozen at the keys that
 * existed that day. So the moment a release adds a key, every one of those shops
 * would have it read as "off" — and now that the navigation honours the setting,
 * "off" means a customer loses a tab nobody chose to take away.
 */
final class SectionBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_key_missing_from_a_saved_list_is_visible_not_hidden(): void
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'sections-backfill.example.com',
            'name' => 'Backfill',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::run($shop, function (): void {
            $settings = MerchantPortalAppearance::current();

            // Exactly what a shop that saved before `downloads` existed carries.
            $settings->sections = [
                ['key' => 'welcome', 'enabled' => true],
                ['key' => 'subscriptions', 'enabled' => true],
                ['key' => 'orders', 'enabled' => true],
                ['key' => 'support', 'enabled' => false],
            ];
            $settings->save();

            $fresh = MerchantPortalAppearance::current()->fresh();

            $this->assertTrue(
                $fresh->sectionEnabled(MerchantPortalAppearance::SECTION_DOWNLOADS),
                'a section the saved list predates must default to visible',
            );
            $this->assertContains(
                MerchantPortalAppearance::SECTION_DOWNLOADS,
                $fresh->visibleSections(),
            );

            // A section the merchant deliberately switched OFF stays off.
            $this->assertFalse($fresh->sectionEnabled(MerchantPortalAppearance::SECTION_SUPPORT));

            // …and their own order is preserved, with the newcomers appended.
            $keys = $fresh->visibleSections();
            $this->assertSame('welcome', $keys[0]);
            $this->assertSame('subscriptions', $keys[1]);
            $this->assertSame('orders', $keys[2]);
        });
    }
}
