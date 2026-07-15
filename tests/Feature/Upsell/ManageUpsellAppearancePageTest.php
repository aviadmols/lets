<?php

namespace Tests\Feature\Upsell;

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
        $this->assertArrayNotHasKey('price', $draft);
        $this->assertArrayNotHasKey('price_display', $draft);
    }
}
