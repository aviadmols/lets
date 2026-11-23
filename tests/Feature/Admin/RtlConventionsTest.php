<?php

namespace Tests\Feature\Admin;

use App\Support\Ui\TextDirection;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Hebrew admin is FULLY RTL. Values (numbers, money, %, signed deltas,
 * dates, ranges) sit in the RTL flow — bidi-ISOLATED (.rc-iso), never forced
 * LTR — and only identifiers that must not reorder (emails, URLs, codes,
 * card tokens, gids) are .rc-ident. Charts mirror in geometry, not with a CSS
 * scaleX(-1). English renders exactly as before.
 */
final class RtlConventionsTest extends TestCase
{
    // === CONSTANTS ===
    private const CSS_DIR = 'resources/css/filament/admin/components';

    /** The only stylesheets allowed to say direction: ltr — code, tokens, the flow canvas plane. */
    private const LTR_ALLOWED_CSS = [
        'campaigns.css',          // .rc-campaign-audience__email — an address
        'code-editor.css',        // HTML/CSS source
        'data-table.css',         // .rc-ident only
        'mail-settings.css',      // .rc-token — {merge_tokens}
        'newsletter-studio.css',  // raw HTML textarea
        'post-purchase.css',      // the flow builder's physical coordinate plane (card text restored to RTL)
        'two-factor.css',         // TOTP secret + recovery codes
    ];

    public function test_no_forced_ltr_value_class_is_left_anywhere(): void
    {
        foreach ([resource_path('views'), app_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\brc-ltr\b/',
                    (string) file_get_contents($file->getPathname()),
                    $file->getPathname().' still uses rc-ltr (use rc-iso for values, rc-ident for identifiers).',
                );
            }
        }
    }

    public function test_value_isolation_keeps_the_page_direction(): void
    {
        $css = (string) file_get_contents(base_path(self::CSS_DIR.'/data-table.css'));

        $this->assertMatchesRegularExpression('/\.rc-iso\s*\{\s*unicode-bidi:\s*isolate;\s*\}/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.rc-iso\s*\{[^}]*direction/', $css, '.rc-iso must inherit the page direction.');
        $this->assertStringContainsString('[dir="rtl"] .rc-iso::before { content: "\200E"; }', $css, 'A sign or range keeps its own order.');
        $this->assertStringContainsString('[dir="rtl"] .rc-ident { text-align: end; }', $css, 'Identifiers still align to the RTL start.');

        foreach (File::files(base_path(self::CSS_DIR)) as $file) {
            if (in_array($file->getFilename(), self::LTR_ALLOWED_CSS, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/direction:\s*ltr/', (string) file_get_contents($file->getPathname()), $file->getFilename().' forces LTR.');
        }
    }

    public function test_text_direction(): void
    {
        $this->assertTrue(TextDirection::isRtl('he'));
        $this->assertTrue(TextDirection::isRtl('he_IL'));
        $this->assertFalse(TextDirection::isRtl('en'));
        $this->assertSame('rtl', TextDirection::of('he'));
        $this->assertSame('ltr', TextDirection::of('en'));
    }
}
