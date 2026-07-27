<?php

namespace App\Support\Ui;

use Illuminate\Support\HtmlString;

/**
 * Renders the ONE inline mark the legal copy needs — **bold** — and nothing else.
 *
 * The privacy notice is authored in lang files by humans, and a few phrases in it
 * carry weight ("card numbers never reach LETS"). Rather than let a full markdown
 * parser near translator-supplied text, this escapes EVERYTHING first and then
 * un-escapes exactly one pattern. A translation can therefore never inject markup,
 * however it is edited — the same reason merchant email bodies are substituted
 * with strtr() instead of being rendered as Blade.
 */
final class LegalText
{
    // === CONSTANTS ===
    /** The only markup honoured: **bold**, non-greedy, no nesting. */
    private const BOLD = '/\*\*(.+?)\*\*/u';

    public static function render(string $text): HtmlString
    {
        $safe = e($text);

        return new HtmlString(
            (string) preg_replace(self::BOLD, '<strong>$1</strong>', $safe)
        );
    }
}
