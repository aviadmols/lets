<?php

namespace Tests\Feature\Upsell;

use App\Models\MerchantUpsellAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The appearance model's guards (Phase 3). Every enum-ish accessor falls back to the house default
 * on bad input, and — the load-bearing guarantee — elements() ALWAYS keeps the locked price / CTA /
 * disclosure present + enabled and drops unknown keys, no matter what the stored JSON says. This is
 * the money + legal safety of the builder, enforced in the model rather than trusted from input.
 */
final class MerchantUpsellAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_current_creates_house_defaults_for_the_bound_shop(): void
    {
        $shop = Shop::create(['name' => 'A', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($shop);

        $a = MerchantUpsellAppearance::current();

        $this->assertSame('light', $a->themeMode());
        $this->assertSame('#000000', $a->accentColor());
        $this->assertSame('#ffffff', $a->accentTextColor());
        $this->assertSame('solid', $a->buttonStyle());
        $this->assertSame(0, $a->cornerRadiusPx());
        $this->assertCount(13, $a->elements());
        $this->assertSame($shop->id, $a->shop_id);
    }

    public function test_bad_values_fall_back_to_the_house_defaults(): void
    {
        $a = new MerchantUpsellAppearance();
        $a->forceFill([
            'theme_mode' => 'neon',
            'accent_color' => 'red',            // not #rrggbb
            'accent_text_color' => '#GGGGGG',   // invalid hex
            'button_style' => 'blob',
            'corner_radius' => 'wobbly',
            'card_shadow' => 'huge',
            'layout' => 'diagonal',
            'image_ratio' => 'wide',
            'decline_style' => 'shout',
        ]);

        $this->assertSame('light', $a->themeMode());
        $this->assertSame('#000000', $a->accentColor());
        $this->assertSame('#ffffff', $a->accentTextColor());
        $this->assertSame('solid', $a->buttonStyle());
        $this->assertSame('sharp', $a->cornerRadius());
        $this->assertSame('soft', $a->cardShadow());
        $this->assertSame('stacked', $a->layout());
        $this->assertSame('natural', $a->imageRatio());
        $this->assertSame('link', $a->declineStyle());
    }

    public function test_valid_hex_is_kept_and_lowercased(): void
    {
        $a = new MerchantUpsellAppearance();
        $a->forceFill(['accent_color' => '#ABCDEF']);

        $this->assertSame('#abcdef', $a->accentColor());
    }

    public function test_elements_drops_unknown_keys_and_forces_the_locked_ones(): void
    {
        $a = new MerchantUpsellAppearance();
        $a->forceFill(['elements' => [
            ['key' => 'headline', 'enabled' => true],
            ['key' => 'price', 'enabled' => false],  // try to DISABLE a locked element
            ['key' => 'bogus', 'enabled' => true],   // unknown → dropped
            ['key' => 'cta'],                         // no `enabled` key
            // disclosure omitted entirely → must be appended + enabled
        ]]);

        $els = $a->elements();
        $keys = array_column($els, 'key');

        $this->assertNotContains('bogus', $keys, 'unknown keys are dropped');
        foreach (MerchantUpsellAppearance::LOCKED_ELEMENTS as $locked) {
            $this->assertContains($locked, $keys, "$locked is always present");
            $row = collect($els)->firstWhere('key', $locked);
            $this->assertTrue($row['enabled'], "$locked is always enabled");
        }
    }

    public function test_empty_elements_falls_back_to_the_default_set(): void
    {
        $a = new MerchantUpsellAppearance();
        $a->forceFill(['elements' => []]);

        $this->assertCount(13, $a->elements());
    }

    public function test_blank_copy_becomes_null_and_long_copy_is_capped(): void
    {
        $a = new MerchantUpsellAppearance();
        $a->forceFill([
            'eyebrow_text' => '   ',
            'badge_text' => str_repeat('x', 80),
        ]);

        $this->assertNull($a->eyebrowText());
        $this->assertSame(48, mb_strlen((string) $a->badgeText()));
    }
}
