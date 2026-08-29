<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\BlockTextSanitizer;
use App\Domain\Campaigns\Studio\NewsletterDocument;

/**
 * What ONE kind of block is: its guard, its defaults, and its label.
 *
 * The definition owns everything type-specific so nothing else has to know a
 * block's shape — the document guard calls cleanContent()/cleanStyles(), the
 * properties panel builds its form off the same vocabulary, and the AI's
 * update_block_content payloads pass through THE SAME cleaners as a human's
 * keystrokes. One guard per type, used by everyone, is the whole safety story.
 *
 * The cleaners follow the house idiom: never throw, drop the unknown, clamp
 * the numeric, fall back to the default. A block that came through here
 * renders, whatever wrote it.
 */
abstract class BlockDefinition
{
    // === CONSTANTS ===
    /** Alignment vocabulary shared by every block that has one. */
    public const ALIGNS = ['start', 'center', 'end'];

    public const MAX_SHORT_TEXT = 300;

    public const MAX_URL = 1000;

    /** The type key this definition owns (`heading`, `text`, …). */
    abstract public function type(): string;

    /** The Hebrew-first label the UI shows (a lang key, resolved by callers via __()). */
    public function labelKey(): string
    {
        return 'studio.block.'.$this->type();
    }

    /**
     * The content a NEW block of this type starts with.
     *
     * @return array<string, mixed>
     */
    abstract public function defaultContent(): array;

    /**
     * Guard the content bag. Same contract as NewsletterDocument::fromArray —
     * degraded input narrows, never corrupts, never throws.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    abstract public function cleanContent(array $raw): array;

    /**
     * Guard the styles bag. The base handles the shared keys; a type with more
     * overrides this and calls up.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function cleanStyles(array $raw): array
    {
        $out = [];

        if (isset($raw['align']) && is_string($raw['align']) && in_array($raw['align'], self::ALIGNS, true)) {
            $out['align'] = $raw['align'];
        }

        $paddingY = NewsletterDocument::clampInt($raw['padding_y'] ?? null, 0, 64, -1);
        if ($paddingY >= 0) {
            $out['padding_y'] = $paddingY;
        }

        return $out;
    }

    // === Shared cleaners for the concrete definitions ===

    protected static function shortText(mixed $value, int $max = self::MAX_SHORT_TEXT): string
    {
        return mb_substr(trim((string) ($value ?? '')), 0, $max);
    }

    /** A URL a block may point at: http(s), mailto, or a `{token}` — else ''. */
    protected static function url(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || mb_strlen($value) > self::MAX_URL) {
            return '';
        }

        return BlockTextSanitizer::hrefAllowed($value) ? $value : '';
    }

    /** An image source: https only — mixed content dies in mail clients too. */
    protected static function imageUrl(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || mb_strlen($value) > self::MAX_URL) {
            return '';
        }

        return str_starts_with(strtolower($value), 'https://') ? $value : '';
    }
}
