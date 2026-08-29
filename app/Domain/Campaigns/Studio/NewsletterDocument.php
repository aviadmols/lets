<?php

namespace App\Domain\Campaigns\Studio;

use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;
use Illuminate\Support\Str;

/**
 * A newsletter, as structure — the studio's source of truth.
 *
 * IMMUTABLE. Every mutation returns a new instance, which is what makes the
 * patch pipeline safe to dry-run: an AI proposal is applied to a copy, shown,
 * and only an approval writes anything.
 *
 * fromArray() IS THE GUARD, in the cleanAudience() idiom the campaigns domain
 * already lives by: unknown block types dropped, invalid values reset to their
 * defaults, numbers clamped, caps enforced — degraded input NARROWS, it never
 * corrupts and it never throws. A document that came out of this class is
 * renderable by construction, whoever wrote the JSON (the editor, an old
 * release, or a model).
 *
 * Block ids are SERVER-minted ULIDs (`blk_…`). Nothing outside this class ever
 * invents one — the AI may only reference ids it was shown, which closes the
 * whole family of collision and spoofing bugs before it opens.
 */
final class NewsletterDocument
{
    // === CONSTANTS ===
    /** The schema this code writes. Readers accept this or older. */
    public const SCHEMA = 1;

    /** A shelf of sixty is already unreadable; more is a runaway loop. */
    public const MAX_BLOCKS = 60;

    /** The whole document, encoded. Well under body_html's own 200k cap. */
    public const MAX_JSON_BYTES = 262_144;

    public const MAX_PREHEADER = 150;

    /** Email container width — the range clients render predictably. */
    public const MIN_WIDTH = 480;

    public const MAX_WIDTH = 640;

    public const DEFAULT_WIDTH = 600;

    public const DIRECTIONS = ['rtl', 'ltr'];

    /** Font KEYS — mapped to full email-safe stacks by the renderer. */
    public const FONTS = ['assistant', 'heebo', 'arial', 'georgia', 'tahoma'];

    public const MIN_RADIUS = 0;

    public const MAX_RADIUS = 24;

    /** The globals every document carries, with the house defaults. */
    public const DEFAULT_GLOBALS = [
        'direction' => 'rtl',
        'width' => self::DEFAULT_WIDTH,
        'background_color' => '#f4f4f5',
        'content_background' => '#ffffff',
        'font_family' => 'assistant',
        'text_color' => '#111827',
        'link_color' => '#2563eb',
        'button_color' => '#111827',
        'button_text_color' => '#ffffff',
        'border_radius' => 8,
    ];

    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    private const BLOCK_ID_PATTERN = '/^blk_[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/';

    /**
     * @param  array<string, mixed>  $globals
     * @param  list<array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>}>  $blocks
     */
    private function __construct(
        private readonly string $preheader,
        private readonly array $globals,
        private readonly array $blocks,
    ) {}

    /** An empty, valid document with the house defaults. */
    public static function empty(): self
    {
        return new self('', self::DEFAULT_GLOBALS, []);
    }

    /**
     * The guard. Whatever came in, what comes out renders.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $preheader = mb_substr(trim((string) ($raw['preheader'] ?? '')), 0, self::MAX_PREHEADER);
        $globals = self::cleanGlobals(is_array($raw['globals'] ?? null) ? $raw['globals'] : []);

        $blocks = [];
        foreach (is_array($raw['blocks'] ?? null) ? $raw['blocks'] : [] as $block) {
            if (count($blocks) >= self::MAX_BLOCKS) {
                break;
            }

            $clean = self::cleanBlock($block);
            if ($clean !== null) {
                $blocks[] = $clean;
            }
        }

        return new self($preheader, $globals, $blocks);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'preheader' => $this->preheader,
            'globals' => $this->globals,
            'blocks' => $this->blocks,
        ];
    }

    // === Reads ===

    public function preheader(): string
    {
        return $this->preheader;
    }

    /** @return array<string, mixed> the full, defaulted globals bag */
    public function globals(): array
    {
        return $this->globals;
    }

    /** @return list<array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>}> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @return array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>}|null */
    public function findBlock(string $id): ?array
    {
        foreach ($this->blocks as $block) {
            if ($block['id'] === $id) {
                return $block;
            }
        }

        return null;
    }

    public function blockIndex(string $id): ?int
    {
        foreach ($this->blocks as $index => $block) {
            if ($block['id'] === $id) {
                return $index;
            }
        }

        return null;
    }

    public function countBlocksOf(string $type): int
    {
        return count(array_filter($this->blocks, static fn (array $b): bool => $b['type'] === $type));
    }

    // === Mutations (each returns a NEW document) ===

    public function withPreheader(string $preheader): self
    {
        return new self(mb_substr(trim($preheader), 0, self::MAX_PREHEADER), $this->globals, $this->blocks);
    }

    /** @param array<string, mixed> $globals partial — merged over the current bag, then re-guarded */
    public function withGlobals(array $globals): self
    {
        return new self($this->preheader, self::cleanGlobals($globals + $this->globals), $this->blocks);
    }

    /** @param list<array<string, mixed>> $blocks re-guarded on the way in */
    public function withBlocks(array $blocks): self
    {
        return self::fromArray([
            'preheader' => $this->preheader,
            'globals' => $this->globals,
            'blocks' => $blocks,
        ]);
    }

    /** A fresh server-side block id. The ONE place ids are minted. */
    public static function newBlockId(): string
    {
        return 'blk_'.Str::ulid()->toBase32();
    }

    // === Guards ===

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private static function cleanGlobals(array $raw): array
    {
        $defaults = self::DEFAULT_GLOBALS;

        $direction = is_string($raw['direction'] ?? null) && in_array($raw['direction'], self::DIRECTIONS, true)
            ? $raw['direction']
            : $defaults['direction'];

        $font = is_string($raw['font_family'] ?? null) && in_array($raw['font_family'], self::FONTS, true)
            ? $raw['font_family']
            : $defaults['font_family'];

        return [
            'direction' => $direction,
            'width' => self::clampInt($raw['width'] ?? null, self::MIN_WIDTH, self::MAX_WIDTH, $defaults['width']),
            'background_color' => self::hexOr($raw['background_color'] ?? null, $defaults['background_color']),
            'content_background' => self::hexOr($raw['content_background'] ?? null, $defaults['content_background']),
            'font_family' => $font,
            'text_color' => self::hexOr($raw['text_color'] ?? null, $defaults['text_color']),
            'link_color' => self::hexOr($raw['link_color'] ?? null, $defaults['link_color']),
            'button_color' => self::hexOr($raw['button_color'] ?? null, $defaults['button_color']),
            'button_text_color' => self::hexOr($raw['button_text_color'] ?? null, $defaults['button_text_color']),
            'border_radius' => self::clampInt($raw['border_radius'] ?? null, self::MIN_RADIUS, self::MAX_RADIUS, $defaults['border_radius']),
        ];
    }

    /**
     * One block through its definition's guard, or null when it cannot exist:
     * an unknown type is DROPPED (forward-compat both ways — an old release
     * reading a newer document skips what it cannot draw, exactly the renderer
     * contract lets-account.js documents), and a foreign or missing id gets a
     * fresh server-minted one rather than being trusted.
     *
     * @return array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>}|null
     */
    private static function cleanBlock(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? $raw['type'] : '';
        $definition = BlockRegistry::for($type);
        if ($definition === null) {
            return null;
        }

        $id = is_string($raw['id'] ?? null) && preg_match(self::BLOCK_ID_PATTERN, $raw['id']) === 1
            ? $raw['id']
            : self::newBlockId();

        return [
            'id' => $id,
            'type' => $type,
            'content' => $definition->cleanContent(is_array($raw['content'] ?? null) ? $raw['content'] : []),
            'styles' => $definition->cleanStyles(is_array($raw['styles'] ?? null) ? $raw['styles'] : []),
        ];
    }

    public static function hexOr(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match(self::HEX_PATTERN, $value) === 1 ? strtolower($value) : $fallback;
    }

    public static function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_int($value) && ! (is_string($value) && is_numeric($value)) && ! is_float($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }
}
