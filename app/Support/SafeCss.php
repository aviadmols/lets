<?php

namespace App\Support;

/**
 * Merchant-authored CSS, made safe to put on a shopper's page.
 *
 * The companion to SafeHtml: a custom offer block is class-based (SafeHtml drops
 * the `style` attribute), so the merchant needs somewhere for the classes to be
 * DEFINED. That somewhere is this string, and it reaches two places — the
 * storefront renderer, which assigns it to a <style> element's textContent, and
 * the admin preview, which writes it into an iframe's srcdoc.
 *
 * THE THREAT MODEL. CSS cannot run script in a modern browser, but it can still:
 *
 *   - smuggle in an EXTERNAL stylesheet (`@import`), which re-opens every door
 *     this class closes and hands a third party a request per page view;
 *   - point a `url()` at a hostile scheme (javascript:/vbscript:/data:) — inert
 *     in current browsers, but this string outlives browser versions;
 *   - carry the legacy script vectors (`expression()`, `behavior:`,
 *     `-moz-binding`) that made IE-era CSS an execution surface;
 *   - carry `<`, which selectors and properties never need. On the storefront a
 *     textContent assignment is breakout-proof regardless, but the admin preview
 *     concatenates this string inside `<style>…</style>` in an iframe srcdoc,
 *     where a literal `</style>` WOULD close the tag. Stripping every `<` makes
 *     the string tag-incapable everywhere it travels. A merchant who wants the
 *     character in generated text writes `content: "\3C"` — the CSS escape.
 *
 * Deliberately NOT a CSS parser. It removes what is named above and trusts the
 * delivery mechanism (textContent / sandboxed iframe) for the rest; anything
 * that needs a real stylesheet pipeline belongs in the theme, not a settings
 * field.
 */
final class SafeCss
{
    // === CONSTANTS ===
    /** Long enough for a real card design, short enough not to be a theme. */
    public const MAX_LENGTH = 20000;

    /** URL schemes a merchant may point a url() at. Relative URLs also pass. */
    public const ALLOWED_URL_SCHEMES = ['https'];

    /** Clean CSS, or null when nothing usable is left. */
    public static function clean(mixed $css): ?string
    {
        if (! is_string($css)) {
            return null;
        }

        $css = trim($css);

        if ($css === '') {
            return null;
        }

        $css = mb_substr($css, 0, self::MAX_LENGTH);

        // Comments first: `@im/**/port` is not @import to a CSS parser, but a
        // string with the comments gone has nowhere left to hide a token split.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // No `<`, ever — see the class docblock. This is what makes the string
        // incapable of becoming markup in the srcdoc preview.
        $css = str_replace('<', '', $css);

        // @import, written plainly or with its name hidden behind CSS escapes
        // (`@\69mport`). The whole statement goes, through its semicolon.
        $css = (string) preg_replace('/@import\b[^;{]*;?/i', '', $css);
        $css = (string) preg_replace_callback(
            '/@([^\s;{(\'"]+)([^;{]*);?/',
            static function (array $m): string {
                return strcasecmp(trim(self::decodeEscapes($m[1])), 'import') === 0 ? '' : $m[0];
            },
            $css,
        );

        // The IE-era execution vectors. Dead in every current browser — removed
        // anyway, because this string is stored, not interpreted, and outlives
        // any statement about "current".
        $css = (string) preg_replace('/expression\s*\(/i', '(', $css);
        $css = (string) preg_replace('/behavior\s*:/i', ':', $css);
        $css = (string) preg_replace('/-moz-binding/i', '', $css);

        $css = self::scrubUrls($css);

        return trim($css) === '' ? null : trim($css);
    }

    /**
     * Every url(): the target is unwrapped, its escapes decoded and its control
     * characters dropped, and the SCHEME judged. https and scheme-less
     * (relative, root-relative, fragment) pass; javascript:, vbscript:, data:
     * and everything else not named do not survive — the whole url() is emptied
     * rather than repaired, because a repaired hostile value is still hostile.
     */
    private static function scrubUrls(string $css): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*("([^"]*)"|\'([^\']*)\'|[^)"\']*)\s*\)/i',
            static function (array $m): string {
                $raw = $m[3] ?? '';
                if ($raw === '') {
                    $raw = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
                }

                // Decode CSS escapes and strip the whitespace/control characters
                // a scheme can hide behind, then read the scheme cold.
                $target = self::decodeEscapes(trim($raw, " \t\n\r\0\x0B\"'"));
                $normalised = strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $target));

                if (preg_match('/^([a-z][a-z0-9+.-]*):/', $normalised, $scheme) === 1) {
                    if (! in_array($scheme[1], self::ALLOWED_URL_SCHEMES, true)) {
                        return 'url()';
                    }
                }

                return $m[0];
            },
            $css,
        );
    }

    /**
     * CSS escape sequences, decoded — for JUDGING a token, never for output.
     * `\69` is `i`, `\3C` is `<`; a scheme or an at-rule name written in escapes
     * must be read the way the browser will read it.
     */
    private static function decodeEscapes(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})[ \t\n]?|\\\\(.)/s',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    $code = (int) hexdec($m[1]);

                    return ($code > 0 && $code <= 0x10FFFF) ? (string) mb_chr($code, 'UTF-8') : '';
                }

                return $m[2] ?? '';
            },
            $value,
        );
    }
}
