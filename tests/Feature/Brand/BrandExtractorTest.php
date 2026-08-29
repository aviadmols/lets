<?php

namespace Tests\Feature\Brand;

use App\Domain\Brand\BrandExtractor;
use Tests\TestCase;

/**
 * The deterministic half of brand capture: HTML + CSS in, a small evidence
 * bag out — most-used colors first, first-position fonts, capped text.
 */
final class BrandExtractorTest extends TestCase
{
    public function test_colors_are_counted_and_the_most_used_lead(): void
    {
        $css = '.a{color:#112233}.b{background:#112233}.c{border-color:#112233}.d{color:#AABBCC}';

        $evidence = (new BrandExtractor)->extract('<html></html>', [$css]);

        $this->assertSame('#112233', $evidence['colors'][0]['hex']);
        $this->assertSame(3, $evidence['colors'][0]['count']);
        $this->assertSame('#aabbcc', $evidence['colors'][1]['hex']);
    }

    public function test_fonts_titles_and_text_come_out_capped_and_clean(): void
    {
        $html = <<<'HTML'
        <html><head>
            <title>  חנות הספרים  </title>
            <meta name="description" content="ספרים לילדים">
            <style>body { font-family: "Heebo", Arial, sans-serif; }</style>
        </head><body>
            <h1>ברוכים הבאים לחנות</h1>
            <p>אנחנו אוהבים <strong>ספרים</strong> ומאמינים בקריאה.</p>
            <p>קצר</p>
        </body></html>
        HTML;

        $evidence = (new BrandExtractor)->extract($html);

        $this->assertSame('חנות הספרים', $evidence['title']);
        $this->assertSame('ספרים לילדים', $evidence['description']);
        $this->assertSame(['Heebo'], $evidence['fonts']);
        $this->assertStringContainsString('ברוכים הבאים לחנות', $evidence['text_sample']);
        // Tags stripped, and the too-short fragment skipped.
        $this->assertStringContainsString('אוהבים ספרים ומאמינים', $evidence['text_sample']);
        $this->assertStringNotContainsString('<strong>', $evidence['text_sample']);
        $this->assertStringNotContainsString('קצר', $evidence['text_sample']);
    }

    public function test_stylesheets_are_same_origin_only_and_capped(): void
    {
        $html = <<<'HTML'
        <link rel="stylesheet" href="/assets/one.css">
        <link rel="stylesheet" href="https://shop.example.com/two.css">
        <link rel="stylesheet" href="//shop.example.com/proto.css">
        <link rel="stylesheet" href="https://cdn.other.com/theme.css">
        <link rel="stylesheet" href="https://shop.example.com/three.css">
        <link rel="stylesheet" href="https://shop.example.com/four.css">
        HTML;

        $urls = (new BrandExtractor)->stylesheetUrls($html, 'https://shop.example.com/he');

        $this->assertCount(3, $urls);
        $this->assertContains('https://shop.example.com/assets/one.css', $urls);
        $this->assertContains('https://shop.example.com/two.css', $urls);
        // Someone else's CDN never gets fetched.
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://shop.example.com', $url);
        }
    }
}
