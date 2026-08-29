<?php

namespace App\Domain\Campaigns\Studio\Render;

/**
 * One compile's output: the HTML, its plain-text sibling, and the honest list
 * of what a careful sender would still want to fix. Warnings never block a
 * save — they are the studio's inline QA voice, not a gate.
 */
final readonly class RenderedEmail
{
    // === CONSTANTS ===
    /** Warning codes; the UI translates each one. */
    public const WARN_UNKNOWN_TOKEN = 'unknown_token';

    public const WARN_IMAGE_WITHOUT_ALT = 'image_without_alt';

    public const WARN_BUTTON_WITHOUT_URL = 'button_without_url';

    public const WARN_NO_FOOTER = 'no_footer';

    /**
     * @param  list<array{code: string, block_id: ?string, detail: ?string}>  $warnings
     */
    public function __construct(
        public string $html,
        public string $text,
        public array $warnings = [],
    ) {}
}
