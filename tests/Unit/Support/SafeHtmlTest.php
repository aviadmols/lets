<?php

namespace Tests\Unit\Support;

use App\Support\SafeHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sanitizer that stands between merchant-authored HTML and a shopper's
 * browser. Every test here is a thing that must NOT survive the trip — this is
 * the one place in the personal area where markup is trusted enough to reach
 * innerHTML, so the guarantee has to be provable rather than assumed.
 */
final class SafeHtmlTest extends TestCase
{
    // === CONSTANTS ===
    /** The token the offer renderer swaps for a real button — it must survive. */
    private const BUTTON_TOKEN = '{{button}}';

    public function test_it_returns_null_for_anything_that_is_not_usable_html(): void
    {
        $this->assertNull(SafeHtml::clean(null));
        $this->assertNull(SafeHtml::clean(123));
        $this->assertNull(SafeHtml::clean('   '));
        $this->assertNull(SafeHtml::clean('<script>alert(1)</script>'));
    }

    public function test_a_script_loses_its_tag_and_its_body(): void
    {
        // strip_tags alone would leave `alert(1)` on the page as text — which is
        // harmless as text but becomes code the moment anything re-parses it.
        $clean = (string) SafeHtml::clean('<p>Hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringContainsString('<p>Hi</p>', $clean);
    }

    public function test_style_iframe_object_and_form_are_removed_with_their_contents(): void
    {
        $clean = (string) SafeHtml::clean(
            '<p>keep</p>'
            .'<style>body{display:none}</style>'
            .'<iframe src="https://evil.test"></iframe>'
            .'<object data="x"></object>'
            .'<form action="https://evil.test"><input name="card"></form>'
        );

        foreach (['style', 'iframe', 'object', 'form', 'display:none', 'evil.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clean);
        }

        $this->assertStringContainsString('keep', $clean);
    }

    public function test_an_unclosed_dangerous_tag_does_not_slip_through(): void
    {
        $clean = (string) SafeHtml::clean('<p>a</p><iframe src="https://evil.test">');

        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('evil.test', $clean);
    }

    public function test_event_handlers_style_and_srcset_attributes_are_dropped(): void
    {
        $clean = (string) SafeHtml::clean(
            '<img src="https://cdn.test/a.png" onerror="alert(1)" style="position:fixed" srcset="x 2x" alt="A">'
        );

        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('style', $clean);
        $this->assertStringNotContainsString('srcset', $clean);
        // …and the allowed ones stay, or the block would be useless.
        $this->assertStringContainsString('src="https://cdn.test/a.png"', $clean);
        $this->assertStringContainsString('alt="A"', $clean);
    }

    public function test_hostile_url_schemes_never_survive(): void
    {
        foreach ([
            '<a href="javascript:alert(1)">x</a>',
            '<a href="JaVaScRiPt:alert(1)">x</a>',
            "<a href=\"java\tscript:alert(1)\">x</a>",
            '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>',
            '<img src="data:image/svg+xml,%3Csvg onload%3D%22alert(1)%22%3E">',
        ] as $hostile) {
            $clean = (string) SafeHtml::clean($hostile);

            $this->assertStringNotContainsString('javascript', strtolower($clean), $hostile);
            $this->assertStringNotContainsString('data:', strtolower($clean), $hostile);
        }
    }

    public function test_relative_and_https_links_are_kept(): void
    {
        $this->assertStringContainsString('href="/shop"', (string) SafeHtml::clean('<a href="/shop">x</a>'));
        $this->assertStringContainsString('href="#top"', (string) SafeHtml::clean('<a href="#top">x</a>'));
        $this->assertStringContainsString(
            'href="https://shop.test/x"',
            (string) SafeHtml::clean('<a href="https://shop.test/x">x</a>'),
        );
        // Plain http is not on the allow-list: a customer page must not be mixed.
        $this->assertStringNotContainsString('href', (string) SafeHtml::clean('<a href="http://shop.test">x</a>'));
    }

    public function test_a_link_gets_rel_noopener_exactly_once(): void
    {
        $added = (string) SafeHtml::clean('<a href="https://shop.test" target="_blank">x</a>');
        $this->assertSame(1, substr_count($added, 'rel='), $added);
        $this->assertStringContainsString('rel="noopener"', $added);

        // The merchant already wrote one — we must not emit a SECOND `rel`, which
        // is invalid markup whose first attribute (theirs) is the one that wins.
        $own = (string) SafeHtml::clean('<a href="https://shop.test" rel="nofollow" target="_blank">x</a>');
        $this->assertSame(1, substr_count($own, 'rel='), $own);
        $this->assertStringContainsString('rel="nofollow"', $own);
    }

    public function test_the_button_token_survives_as_text(): void
    {
        // The whole custom-HTML feature rests on this: the token is TEXT, so
        // strip_tags cannot eat it, and the renderer can swap it for a button.
        $clean = (string) SafeHtml::clean('<div class="promo"><p>Save more</p>'.self::BUTTON_TOKEN.'</div>');

        $this->assertStringContainsString(self::BUTTON_TOKEN, $clean);
        $this->assertStringContainsString('class="promo"', $clean);
    }

    public function test_input_is_truncated_at_the_maximum_length(): void
    {
        $long = str_repeat('a', SafeHtml::MAX_LENGTH + 500);

        $this->assertSame(SafeHtml::MAX_LENGTH, mb_strlen((string) SafeHtml::clean($long)));
    }

    /**
     * An EMPTY attribute value used to crash the sanitiser outright — a 500 on
     * saving an offer, from markup as ordinary as `<img alt="">`.
     *
     * preg_match_all with PREG_SET_ORDER drops trailing groups that did not
     * participate, so a double-quoted attribute has no third or fourth group at
     * all; reading them positionally only survived while every value happened to
     * be non-empty. Each quoting style is pinned here, empty and not.
     */
    #[DataProvider('emptyAttributeMarkup')]
    public function test_an_empty_attribute_value_does_not_break_the_sanitiser(string $html, string $expected): void
    {
        $this->assertSame($expected, SafeHtml::clean($html));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function emptyAttributeMarkup(): array
    {
        return [
            'empty double-quoted' => ['<img alt="" title="Gift">', '<img alt="" title="Gift">'],
            'empty single-quoted' => ["<img alt='' title='Gift'>", '<img alt="" title="Gift">'],
            'empty class on a div' => ['<div class=""><p>Hello</p></div>', '<div class=""><p>Hello</p></div>'],
            'unquoted value' => ['<img width=320>', '<img width="320">'],
            'every attribute empty' => ['<img alt="" title="">', '<img alt="" title="">'],
        ];
    }
}
