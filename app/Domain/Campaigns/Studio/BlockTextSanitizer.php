<?php

namespace App\Domain\Campaigns\Studio;

/**
 * The ONE place a text block's limited HTML is cleaned.
 *
 * A text block may carry a small formatting subset — bold, lists, links — and
 * everything else is stripped, whoever wrote it: the merchant in the
 * properties panel, an old document, or a model whose patch payload claimed to
 * be text. The walker rebuilds the fragment from an ALLOWLIST rather than
 * deleting from a blocklist, so anything unanticipated simply does not survive.
 *
 * `{token}` placeholders pass through untouched — braces are not HTML — and a
 * body carrying template syntax of any other flavour (`{{ 7*7 }}`, `@php`)
 * comes out as the inert literal text it will remain: substitution is
 * strtr-only in this app, and this class must never create a reason to relax
 * that.
 *
 * href is the one attribute kept, and only when it is http(s), mailto, or a
 * known placeholder token — a javascript: URL dies here, not in a mail client.
 */
final class BlockTextSanitizer
{
    // === CONSTANTS ===
    /** Tags a text block may carry. Everything else is unwrapped to its text. */
    public const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 'a', 'br', 'p', 'ul', 'ol', 'li', 'span'];

    /**
     * Elements whose TEXT is code, not words — dropped whole, subtree included.
     * Unwrapping a <script> would keep its source as visible "text", which is
     * not a merchant's paragraph by any reading.
     */
    public const DROPPED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'textarea', 'title', 'noscript'];

    /** Where a link may point. */
    private const HREF_PATTERN = '#^(https?://|mailto:)#i';

    private const HREF_TOKEN_PATTERN = '/^\{[a-z0-9_]+\}$/';

    public const MAX_LENGTH = 20_000;

    /** Cleaned fragment, safe to inline into the compiled email. */
    public static function clean(mixed $html): string
    {
        $html = trim((string) ($html ?? ''));
        if ($html === '') {
            return '';
        }

        $html = mb_substr($html, 0, self::MAX_LENGTH);

        $doc = new \DOMDocument;

        // The fragment is parsed as UTF-8 inside a body wrapper; libxml noise
        // (unknown tags are exactly what we expect) is suppressed, never fatal.
        $previous = libxml_use_internal_errors(true);

        try {
            $doc->loadHTML(
                '<?xml encoding="utf-8"?><body>'.$html.'</body>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= self::render($node);
        }

        return trim($out);
    }

    /** Rebuild one node from the allowlist out. */
    private static function render(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->wholeText, ENT_QUOTES, 'UTF-8');
        }

        if (! $node instanceof \DOMElement) {
            return ''; // comments, processing instructions — nothing to keep.
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::render($child);
        }

        // An unknown element is UNWRAPPED, not deleted: a <div> loses its box
        // but keeps its words — stripping a merchant's paragraph because a
        // paste carried markup would read as data loss.
        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            return $inner;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        $attributes = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (self::hrefAllowed($href)) {
                $attributes = ' href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'"';
            }
        }

        return '<'.$tag.$attributes.'>'.$inner.'</'.$tag.'>';
    }

    /** http(s), mailto, or a `{token}` — nothing else becomes a destination. */
    public static function hrefAllowed(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        return preg_match(self::HREF_PATTERN, $href) === 1
            || preg_match(self::HREF_TOKEN_PATTERN, $href) === 1;
    }
}
