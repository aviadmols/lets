<?php

namespace Tests\Feature\Upsell;

use Tests\TestCase;

/**
 * The card's side-by-side layout.
 *
 * The renderer has always emitted `.lets-ppu__body` / `.lets-ppu__content` when a
 * merchant picks "image beside the text" — and the stylesheet had no rule for
 * either, so the option produced exactly the stacked card it was meant to replace.
 * A merchant who chose it saw no difference and had no way to know why.
 *
 * These pin the contract between the renderer and the stylesheet, and the fact
 * that the storefront copy and the SaaS preview copy are the SAME file. A preview
 * that disagrees with the storefront is worse than no preview.
 */
final class UpsellCardLayoutTest extends TestCase
{
    // === CONSTANTS ===
    private const PLUGIN_CSS = 'plugins/lets-payplus-woocommerce/assets/css/lets-ppu.css';
    private const PLUGIN_JS = 'plugins/lets-payplus-woocommerce/assets/js/lets-ppu.js';
    private const SAAS_CSS = 'public/upsell/lets-ppu.css';
    private const SAAS_JS = 'public/upsell/lets-ppu.js';

    public function test_the_two_copies_of_the_card_are_byte_identical(): void
    {
        // The storefront loads the plugin's copy; the admin preview loads the
        // SaaS's. They must be the same file or the preview lies.
        $this->assertSame(
            md5_file(base_path(self::PLUGIN_CSS)),
            md5_file(base_path(self::SAAS_CSS)),
            'lets-ppu.css has drifted between the plugin and the SaaS',
        );
        $this->assertSame(
            md5_file(base_path(self::PLUGIN_JS)),
            md5_file(base_path(self::SAAS_JS)),
            'lets-ppu.js has drifted between the plugin and the SaaS',
        );
    }

    public function test_the_side_by_side_layout_is_actually_styled(): void
    {
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // The two nodes the renderer emits for this layout.
        $this->assertStringContainsString('.lets-ppu__body', $css);
        $this->assertStringContainsString('.lets-ppu__content', $css);

        // …and the rule that makes it two columns rather than two stacked blocks.
        $this->assertMatchesRegularExpression('/\.lets-ppu__body\s*\{[^}]*display:\s*grid/s', $css);
        $this->assertStringContainsString('grid-template-columns', $css);
    }

    public function test_it_stacks_on_a_phone(): void
    {
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // Two columns are declared INSIDE a min-width query, so the default — what a
        // phone gets — is a single column, image above the copy.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(min-width:[^)]+\)\s*\{\s*\.lets-ppu__body\s*\{[^}]*grid-template-columns/s',
            $css,
            'the two-column rule must be gated behind a min-width, or phones get two cramped columns',
        );
    }

    public function test_the_renderer_marks_the_layout_the_stylesheet_keys_on(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // A stylesheet keyed on an attribute nobody sets is dead CSS — which is
        // the exact failure this test exists to prevent recurring.
        $this->assertStringContainsString("setAttribute('data-layout'", $js);
        $this->assertStringContainsString('media_side', $js);
        $this->assertStringContainsString('[data-layout="media_side"]', $css);
    }
}
