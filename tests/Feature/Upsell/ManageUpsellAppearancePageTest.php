<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Filament\Pages\ManageUpsellAppearance;
use App\Models\MerchantUpsellAppearance;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Settings → Upsell card design page (Phase 3). It renders, persists through the model guards
 * (so a tampered value or a de-locked element can never reach the DB), and its live-preview draft
 * carries only appearance tokens — never money.
 */
final class ManageUpsellAppearancePageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['shopify_domain' => 'appr.myshopify.com', 'name' => 'Appr', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_page_renders_its_sections(): void
    {
        Livewire::test(ManageUpsellAppearance::class)
            ->assertOk()
            ->assertSee(__('upsell.appearance.brand.heading'))
            ->assertSee(__('upsell.appearance.layout.heading'))
            ->assertSee(__('upsell.appearance.elements.heading'));
    }

    public function test_the_card_speaks_the_language_the_merchant_chose(): void
    {
        $presenter = app(UpsellCardPresenter::class);
        $appearance = MerchantUpsellAppearance::current();

        // Untouched: English, as every card spoke before the choice existed.
        $this->assertSame(MerchantUpsellAppearance::LOCALE_EN, $appearance->cardLocale());
        $this->assertSame('No thanks', $presenter->sample($appearance, 'woocommerce')['content']['decline_cta']);

        $appearance->forceFill(['card_locale' => MerchantUpsellAppearance::LOCALE_HE])->save();
        $card = $presenter->sample($appearance->fresh(), 'woocommerce');

        $this->assertSame(__('upsell.decline_cta', [], 'he'), $card['content']['decline_cta']);
        $this->assertSame(__('upsell.no_card_reentry', [], 'he'), $card['content']['trust']);
        $this->assertSame('en', app()->getLocale(), "the admin's own language is restored after the card is built");
    }

    public function test_the_card_language_is_saved_and_the_preview_follows_it(): void
    {
        Livewire::test(ManageUpsellAppearance::class)
            ->assertSet('data.card_locale', MerchantUpsellAppearance::LOCALE_EN)
            ->set('data.card_locale', MerchantUpsellAppearance::LOCALE_HE)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('lets-appearance-reload');

        $this->assertSame(MerchantUpsellAppearance::LOCALE_HE, MerchantUpsellAppearance::current()->fresh()->cardLocale());

        // The preview page reads right-to-left for an admin working in English.
        $this->get((new ManageUpsellAppearance)->previewUrl())->assertOk()->assertSee('dir="rtl"', false);

        // A tampered value never reaches the card: it falls back to English.
        Livewire::test(ManageUpsellAppearance::class)->set('data.card_locale', 'fr')->call('save');
        $this->assertSame(MerchantUpsellAppearance::LOCALE_EN, MerchantUpsellAppearance::current()->fresh()->cardLocale());
    }

    public function test_save_persists_and_enforces_the_locked_elements(): void
    {
        Livewire::test(ManageUpsellAppearance::class)
            ->set('data.accent_color', '#ff0000')
            ->set('data.theme_mode', 'dark')
            ->set('data.button_style', 'outline')
            // Try to remove/disable the locked price — the save must re-enable it.
            ->set('data.elements', [
                ['key' => 'headline', 'enabled' => true],
                ['key' => 'price', 'enabled' => false],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $saved = MerchantUpsellAppearance::current();
        $this->assertSame('#ff0000', $saved->accentColor());
        $this->assertSame('dark', $saved->themeMode());
        $this->assertSame('outline', $saved->buttonStyle());

        $price = collect($saved->elements())->firstWhere('key', 'price');
        $this->assertTrue($price['enabled'], 'the locked price is re-enabled on save');
        $this->assertContains('disclosure', array_column($saved->elements(), 'key'));
    }

    public function test_draft_appearance_carries_tokens_but_no_money(): void
    {
        $draft = Livewire::test(ManageUpsellAppearance::class)
            ->instance()
            ->draftAppearance();

        $this->assertArrayHasKey('accent', $draft);
        $this->assertArrayHasKey('elements', $draft);
        $this->assertArrayHasKey('grid_columns', $draft);
        $this->assertArrayHasKey('facts', $draft);
        $this->assertArrayNotHasKey('price', $draft);
        $this->assertArrayNotHasKey('price_display', $draft);
    }

    /**
     * The grid layout: chosen, saved through the guards, and its words reach the live
     * preview resolved — the merchant's text where typed, the default otherwise.
     */
    public function test_the_grid_layout_is_saved_and_its_words_reach_the_preview(): void
    {
        $page = Livewire::test(ManageUpsellAppearance::class)
            ->set('data.layout', MerchantUpsellAppearance::LAYOUT_GRID)
            ->set('data.grid_columns', 8)
            ->set('data.display_font', MerchantUpsellAppearance::DISPLAY_SERIF)
            ->set('data.timer_label', 'Closes in')
            ->set('data.facts_text', 'Ships together | One charge')
            ->set('data.picker_title', '   ');

        $draft = $page->instance()->draftAppearance();
        $this->assertSame('grid', $draft['layout']);
        $this->assertSame(8, $draft['grid_columns']);
        $this->assertSame('serif', $draft['display_font']);
        $this->assertSame('Closes in', $draft['timer_label']);
        $this->assertSame(['Ships together', 'One charge'], $draft['facts']);
        $this->assertSame(__('upsell.grid.picker_title'), $draft['picker_title'], 'blank → the built-in words');

        $page->call('save')->assertHasNoErrors();

        $saved = MerchantUpsellAppearance::current()->fresh();
        $this->assertSame('grid', $saved->layout());
        $this->assertSame(8, $saved->gridColumns());
        $this->assertSame('serif', $saved->displayFont());
        $this->assertSame('Closes in', $saved->timerLabel());
        $this->assertNull($saved->pickerTitle());

        // A tampered column count never reaches the row as typed: clamped by the guard.
        Livewire::test(ManageUpsellAppearance::class)->set('data.grid_columns', 99)->call('save');
        $this->assertSame(8, MerchantUpsellAppearance::current()->fresh()->gridColumns());
    }
}
