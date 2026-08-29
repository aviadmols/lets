<?php

namespace App\Domain\Brand;

/**
 * Deterministic extraction: HTML + CSS in, a compact evidence bag out.
 *
 * No model here — this is counting and pattern-matching, and it exists so the
 * analyzer's prompt carries EVIDENCE (the twelve most-used colors, the font
 * declarations, a text sample) instead of two megabytes of markup. Everything
 * that comes out is still UNTRUSTED site content; the analyzer treats it so.
 */
final class BrandExtractor
{
    // === CONSTANTS ===
    public const MAX_COLORS = 12;

    public const MAX_FONTS = 8;

    public const MAX_TEXT_SAMPLE = 2000;

    /**
     * @param  list<string>  $cssBodies
     * @return array{colors: list<array{hex: string, count: int}>, fonts: list<string>, title: string, description: string, text_sample: string}
     */
    public function extract(string $html, array $cssBodies = []): array
    {
        $allCss = implode("\n", $cssBodies)."\n".$this->inlineStyles($html);

        return [
            'colors' => $this->colors($allCss.$html),
            'fonts' => $this->fonts($allCss),
            'title' => $this->firstMatch('/<title[^>]*>(.*?)<\/title>/is', $html),
            'description' => $this->metaContent($html, 'description'),
            'text_sample' => $this->textSample($html),
        ];
    }

    /**
     * The stylesheet URLs a page names, same-origin only — someone else's CDN
     * theme says nothing about THIS brand, and fetching it widens the surface.
     *
     * @return list<string>
     */
    public function stylesheetUrls(string $html, string $pageUrl): array
    {
        preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $links);

        $origin = $this->origin($pageUrl);
        $urls = [];

        foreach ($links[0] as $tag) {
            if (preg_match('/href=["\']([^"\']+)["\']/', $tag, $m) !== 1) {
                continue;
            }

            $href = html_entity_decode($m[1]);

            if (str_starts_with($href, '//')) {
                $href = 'https:'.$href;
            } elseif (str_starts_with($href, '/')) {
                $href = $origin.$href;
            } elseif (preg_match('#^https?://#i', $href) !== 1) {
                continue;
            }

            if (str_starts_with($href, $origin)) {
                $urls[] = $href;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, 3);
    }

    // === Internals ===

    /** @return list<array{hex: string, count: int}> most-used first */
    private function colors(string $haystack): array
    {
        preg_match_all('/#([0-9a-fA-F]{6})\b/', $haystack, $matches);

        $counts = [];
        foreach ($matches[1] as $hex) {
            $hex = strtolower($hex);
            $counts[$hex] = ($counts[$hex] ?? 0) + 1;
        }

        arsort($counts);

        $out = [];
        foreach (array_slice($counts, 0, self::MAX_COLORS, true) as $hex => $count) {
            $out[] = ['hex' => '#'.$hex, 'count' => $count];
        }

        return $out;
    }

    /** @return list<string> the declared families, first-position names */
    private function fonts(string $css): array
    {
        preg_match_all('/font-family\s*:\s*([^;{}]+)[;}]/i', $css, $matches);

        $fonts = [];
        foreach ($matches[1] as $declaration) {
            $first = trim(explode(',', $declaration)[0], " \t\"'");
            if ($first !== '' && ! in_array($first, $fonts, true)) {
                $fonts[] = mb_substr($first, 0, 60);
            }
            if (count($fonts) >= self::MAX_FONTS) {
                break;
            }
        }

        return $fonts;
    }

    /** Headline + paragraph text, tags stripped, capped — the tone's evidence. */
    private function textSample(string $html): string
    {
        preg_match_all('/<(h1|h2|h3|p)[^>]*>(.*?)<\/\1>/is', $html, $matches);

        $parts = [];
        $length = 0;

        foreach ($matches[2] as $fragment) {
            $text = trim(html_entity_decode(strip_tags($fragment)));
            if (mb_strlen($text) < 8) {
                continue;
            }

            $parts[] = $text;
            $length += mb_strlen($text);

            if ($length >= self::MAX_TEXT_SAMPLE) {
                break;
            }
        }

        return mb_substr(implode("\n", $parts), 0, self::MAX_TEXT_SAMPLE);
    }

    private function inlineStyles(string $html): string
    {
        preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $matches);

        return implode("\n", $matches[1]);
    }

    private function firstMatch(string $pattern, string $html): string
    {
        return preg_match($pattern, $html, $m) === 1
            ? mb_substr(trim(html_entity_decode(strip_tags($m[1]))), 0, 200)
            : '';
    }

    private function metaContent(string $html, string $name): string
    {
        return preg_match(
            '/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i',
            $html,
            $m,
        ) === 1 ? mb_substr(trim(html_entity_decode($m[1])), 0, 300) : '';
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }
}
