<?php

namespace Tests\Unit\Support;

use App\Support\SafeCss;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sanitizer that stands between a merchant's offer stylesheet and a
 * shopper's page. The delivery is already breakout-proof (textContent on the
 * storefront, a sandboxed iframe in the admin) — these pin the string-level
 * guarantees on top of that: no external stylesheet smuggling, no hostile
 * url() scheme, no legacy execution vector, and no `<` at all, because the
 * admin preview concatenates this string inside <style>…</style> where a
 * literal `</style>` would close the tag.
 */
final class SafeCssTest extends TestCase
{
    // === CONSTANTS ===
    /** A rule an honest merchant would write — it must survive untouched. */
    private const HONEST = '.my-offer .title { color: #c00; font-weight: 700; }';

    public function test_it_returns_null_for_anything_that_is_not_usable_css(): void
    {
        $this->assertNull(SafeCss::clean(null));
        $this->assertNull(SafeCss::clean(123));
        $this->assertNull(SafeCss::clean('   '));
        $this->assertNull(SafeCss::clean('/* only a comment */'));
        $this->assertNull(SafeCss::clean('@import url(https://evil.test/a.css);'));
    }

    public function test_an_honest_rule_survives_untouched(): void
    {
        $this->assertSame(self::HONEST, SafeCss::clean(self::HONEST));
    }

    /**
     * `@import` is the one CSS statement that re-opens every door this class
     * closes: it pulls in a whole stylesheet from somewhere else, after the
     * cleaning already happened. It is removed however it is written — plainly,
     * split around comments, or with its name hidden behind CSS escapes.
     */
    #[DataProvider('importSmuggling')]
    public function test_an_import_never_survives(string $css): void
    {
        $clean = (string) SafeCss::clean($css);

        $this->assertStringNotContainsStringIgnoringCase('import', $clean, $css);
        $this->assertStringNotContainsString('evil.test', $clean, $css);
        $this->assertStringContainsString('.keep', $clean, $css);
    }

    /** @return array<string, array{0: string}> */
    public static function importSmuggling(): array
    {
        return [
            'plain' => ['.keep{color:red}@import url(https://evil.test/a.css);'],
            'quoted, no url()' => ['.keep{color:red}@import "https://evil.test/a.css";'],
            'uppercase' => ['.keep{color:red}@IMPORT url(https://evil.test/a.css);'],
            'comment-split' => ['.keep{color:red}@imp/* x */ort url(https://evil.test/a.css);'],
            'escaped name' => ['.keep{color:red}@\69mport url(https://evil.test/a.css);'],
        ];
    }

    /** The IE-era execution vectors, removed even though no current browser runs them. */
    public function test_the_legacy_execution_vectors_are_stripped(): void
    {
        $clean = (string) SafeCss::clean(
            '.keep { width: expression(alert(1)); behavior: url(#default#time2); -moz-binding: url(x.xml#p); }',
        );

        $this->assertStringNotContainsStringIgnoringCase('expression', $clean);
        $this->assertStringNotContainsStringIgnoringCase('behavior', $clean);
        $this->assertStringNotContainsStringIgnoringCase('-moz-binding', $clean);
        $this->assertStringContainsString('.keep', $clean);
    }

    #[DataProvider('hostileUrls')]
    public function test_a_hostile_url_scheme_never_survives(string $css, string $forbidden): void
    {
        $clean = (string) SafeCss::clean($css);

        $this->assertStringNotContainsStringIgnoringCase($forbidden, $clean, $css);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function hostileUrls(): array
    {
        return [
            'javascript:' => ['.x{background:url(javascript:alert(1))}', 'javascript'],
            'javascript, quoted' => ['.x{background:url("javascript:alert(1)")}', 'javascript'],
            'javascript, spaced' => [".x{background:url(java\tscript:alert(1))}", 'alert'],
            'javascript, escaped' => ['.x{background:url(\6a avascript:alert(1))}', 'alert'],
            'vbscript:' => ['.x{background:url(vbscript:msgbox(1))}', 'vbscript'],
            'data:' => ['.x{background:url(data:text/html;base64,PHNjcmlwdD4=)}', 'data:'],
            'http (not on the allow-list)' => ['.x{background:url(http://evil.test/p.png)}', 'evil.test'],
        ];
    }

    public function test_https_and_relative_urls_are_kept(): void
    {
        $this->assertSame(
            '.x{background:url(https://cdn.example.com/p.png)}',
            SafeCss::clean('.x{background:url(https://cdn.example.com/p.png)}'),
        );
        $this->assertSame(
            '.x{background:url("/img/p.png")}',
            SafeCss::clean('.x{background:url("/img/p.png")}'),
        );
    }

    /**
     * No literal `<`, anywhere: selectors and properties never need one, and the
     * admin preview concatenates this string inside <style>…</style> where a
     * `</style>` would close the tag and everything after it would be MARKUP.
     * A merchant who wants the character in generated text writes `\3C`.
     */
    public function test_every_literal_angle_bracket_is_stripped(): void
    {
        $clean = (string) SafeCss::clean(
            '.x::before { content: "</style><script>alert(1)</script>"; }',
        );

        $this->assertStringNotContainsString('<', $clean);

        // …and the documented escape spelling is untouched.
        $escaped = '.x::before { content: "\3C"; }';
        $this->assertSame($escaped, SafeCss::clean($escaped));
    }

    public function test_input_is_truncated_at_the_maximum_length(): void
    {
        $long = str_repeat('a', SafeCss::MAX_LENGTH + 500);

        $this->assertSame(SafeCss::MAX_LENGTH, mb_strlen((string) SafeCss::clean($long)));
    }

    public function test_comments_are_removed_so_nothing_can_hide_inside_one(): void
    {
        $this->assertSame(
            '.keep{color:red}',
            SafeCss::clean("/* a note */.keep{color:red}/* another\nnote */"),
        );
    }
}
