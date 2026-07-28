<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Http\Controllers\PostPurchaseController;
use App\Domain\Upsell\Rendering\PostPurchasePresenter;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Filament\Pages\ManageUpsellAppearance;
use App\Models\MerchantUpsellAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the post-purchase surface may and may not be told to look like.
 *
 * Shopify draws its post-purchase page from its OWN components and forbids custom
 * CSS, so half the appearance settings have no expression there. The presenter is
 * where that truth is stated once — and it feeds BOTH the extension payload and
 * the admin preview, which is what makes the preview faithful by construction
 * rather than by somebody remembering to keep two renderers in step.
 */
final class PostPurchasePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_merchants_copy_reaches_the_surface(): void
    {
        $appearance = $this->appearance(['eyebrow_text' => 'Only here', 'trust_text' => 'No re-entry']);
        $presentation = $this->present($appearance);

        // The whole point: these strings were configured in the admin and used to
        // be dropped in transit, leaving the extension rendering hardcoded English.
        $this->assertSame('Only here', $presentation['copy']['eyebrow']);
        $this->assertSame('No re-entry', $presentation['copy']['trust']);
        $this->assertNotEmpty($presentation['copy']['accept_cta']);
        $this->assertNotEmpty($presentation['copy']['disclosure']);
    }

    public function test_element_order_survives_the_translation(): void
    {
        // Order is a real design decision — price above the headline is a
        // different offer — so it crosses even though the styling cannot.
        $appearance = $this->appearance(['elements' => [
            ['key' => 'price', 'enabled' => true],
            ['key' => 'headline', 'enabled' => true],
            ['key' => 'trust', 'enabled' => true],
        ]]);

        $order = $this->present($appearance)['order'];

        $this->assertSame(['price', 'headline', 'trust'], array_slice($order, 0, 3));
    }

    public function test_a_disabled_element_is_not_offered_to_the_renderer(): void
    {
        $appearance = $this->appearance(['elements' => [
            ['key' => 'headline', 'enabled' => true],
            ['key' => 'image', 'enabled' => false],
        ]]);

        $presentation = $this->present($appearance);

        $this->assertFalse($presentation['elements']['image']);
        $this->assertNotContains('image', $presentation['order']);
    }

    public function test_the_locked_elements_survive_a_merchant_turning_them_off(): void
    {
        $appearance = $this->appearance(['elements' => [
            ['key' => 'price', 'enabled' => false],
            ['key' => 'cta', 'enabled' => false],
            ['key' => 'disclosure', 'enabled' => false],
        ]]);

        $presentation = $this->present($appearance);

        // Money + legal safety by construction: no surface may hide the price, the
        // buy button, or the consent disclosure.
        foreach (MerchantUpsellAppearance::LOCKED_ELEMENTS as $locked) {
            $this->assertTrue($presentation['elements'][$locked], "{$locked} must not be removable.");
        }
    }

    public function test_an_element_shopify_cannot_draw_is_dropped_not_mis_rendered(): void
    {
        $appearance = $this->appearance(['elements' => [
            ['key' => 'headline', 'enabled' => true],
            ['key' => 'timer', 'enabled' => true],
        ]]);

        $presentation = $this->present($appearance);

        // A countdown needs a re-render loop the post-purchase runtime does not
        // give us, and a frozen timer reads as broken.
        $this->assertNotContains('timer', $presentation['order']);
        $this->assertArrayNotHasKey('timer', $presentation['elements']);
    }

    public function test_it_names_the_settings_that_cannot_apply(): void
    {
        $presentation = $this->present($this->appearance([]));

        // Named, not silently ignored — this list is what the admin shows so a
        // merchant never tunes a control that does nothing on this surface.
        $this->assertContains('accent_color', $presentation['unsupported_settings']);
        $this->assertContains('corner_radius', $presentation['unsupported_settings']);
        $this->assertContains('theme_font', $presentation['unsupported_settings']);
    }

    public function test_a_shopify_shop_previews_the_shopify_surface(): void
    {
        Tenant::set($this->shop(Shop::PLATFORM_SHOPIFY));

        // The preview was pinned to WooCommerce, so a Shopify merchant tuned our
        // HTML card and then met Shopify's components on the real page.
        $this->assertSame(PostPurchaseController::PLATFORM, (new ManageUpsellAppearance())->previewPlatform());
        $this->assertNotEmpty((new ManageUpsellAppearance())->inertSettings());
    }

    public function test_the_previewed_platform_is_actually_routable(): void
    {
        Tenant::set($this->shop(Shop::PLATFORM_SHOPIFY));

        // The route whitelists `platform` because each value draws a different
        // surface — so pointing the preview at a new one without listing it there
        // resolves to 404, which is exactly what the iframe showed. Not-404 is the
        // assertion: a redirect to login still proves the route matched.
        $response = $this->get((new ManageUpsellAppearance())->previewUrl());

        $this->assertNotSame(404, $response->getStatusCode(), 'The preview platform must be routable.');
    }

    public function test_a_woocommerce_shop_still_previews_its_own_card(): void
    {
        Tenant::set($this->shop(Shop::PLATFORM_WOOCOMMERCE));

        // The WooCommerce card renders OUR markup, where every setting applies —
        // nothing about it changes.
        $page = new ManageUpsellAppearance();
        $this->assertSame(UpsellCardPresenter::PLATFORM_WOOCOMMERCE, $page->previewPlatform());
        $this->assertSame([], $page->inertSettings());
    }

    // === Helpers ===

    /** @param array<string, mixed> $attributes */
    private function appearance(array $attributes): MerchantUpsellAppearance
    {
        if (! Tenant::check()) {
            Tenant::set($this->shop(Shop::PLATFORM_SHOPIFY));
        }

        // current() is the factory that seeds the full default set; overriding on
        // top of it keeps the fixture a real row rather than a sparse one.
        $appearance = MerchantUpsellAppearance::current();
        if ($attributes !== []) {
            $appearance->forceFill($attributes)->save();
        }

        return $appearance->fresh();
    }

    /** @return array<string, mixed> */
    private function present(MerchantUpsellAppearance $appearance): array
    {
        $card = app(UpsellCardPresenter::class)->sample($appearance, PostPurchaseController::PLATFORM);

        return app(PostPurchasePresenter::class)->present($card, $appearance);
    }

    private function shop(string $platform): Shop
    {
        return Shop::create([
            'shopify_domain' => 'pp-preview.myshopify.com',
            'name' => 'PP Preview',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => $platform,
        ]);
    }
}
