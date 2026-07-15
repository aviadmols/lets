<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-shop APPEARANCE for the post-purchase upsell card (Phase 3). Exactly ONE row per shop,
 * lazily created with house-style defaults on first read (current()). Tenant-scoped (shop_id +
 * BelongsToShop); shop_id is guarded so a raw create/update can never re-key the row to another
 * tenant — a sibling of MerchantCheckoutSettings / MerchantBillingSettings.
 *
 * It tunes the LOOK of the ONE shared card (public/upsell/lets-ppu.{css,js}) that both the live
 * WooCommerce widget and the Filament preview render byte-identically. It carries NO money and NO
 * secrets: the price, the buy CTA and the legal consent disclosure are LOCKED_ELEMENTS —
 * force-injected + force-enabled by the elements() accessor — so a merchant can never design away
 * the money, the buy button, or the disclosure, no matter what the stored JSON says.
 *
 * Every enum-ish accessor guards against a CONST allow-list and falls back to the house default;
 * bad merchant input can never escape into the rendered card.
 */
class MerchantUpsellAppearance extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'merchant_upsell_appearance';

    /** theme_mode. */
    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';
    public const THEME_MODES = [self::THEME_LIGHT, self::THEME_DARK];

    /** button_style. */
    public const BUTTON_SOLID = 'solid';
    public const BUTTON_OUTLINE = 'outline';
    public const BUTTON_STYLES = [self::BUTTON_SOLID, self::BUTTON_OUTLINE];

    /** corner_radius → CTA border-radius in px. House default = sharp (0). */
    public const RADIUS_SHARP = 'sharp';
    public const RADIUS_SOFT = 'soft';
    public const RADIUS_PILL = 'pill';
    public const CORNER_RADII = [self::RADIUS_SHARP, self::RADIUS_SOFT, self::RADIUS_PILL];
    public const RADIUS_PX = [
        self::RADIUS_SHARP => 0,
        self::RADIUS_SOFT => 7,
        self::RADIUS_PILL => 999,
    ];

    /** card_shadow (mapped to a self-contained shadow value in the CSS via data-shadow). */
    public const SHADOW_NONE = 'none';
    public const SHADOW_SOFT = 'soft';
    public const SHADOW_ELEVATED = 'elevated';
    public const CARD_SHADOWS = [self::SHADOW_NONE, self::SHADOW_SOFT, self::SHADOW_ELEVATED];

    /** theme_font — the webfont (Heebo) vs the host's system font. */
    public const FONT_HEEBO = 'heebo';
    public const FONT_SYSTEM = 'system';
    public const FONTS = [self::FONT_HEEBO, self::FONT_SYSTEM];

    /** layout. */
    public const LAYOUT_STACKED = 'stacked';
    public const LAYOUT_MEDIA_SIDE = 'media_side';
    public const LAYOUTS = [self::LAYOUT_STACKED, self::LAYOUT_MEDIA_SIDE];

    /** image_ratio. */
    public const RATIO_NATURAL = 'natural';
    public const RATIO_SQUARE = 'square';
    public const IMAGE_RATIOS = [self::RATIO_NATURAL, self::RATIO_SQUARE];

    /** decline_style. */
    public const DECLINE_LINK = 'link';
    public const DECLINE_BUTTON = 'button';
    public const DECLINE_STYLES = [self::DECLINE_LINK, self::DECLINE_BUTTON];

    /** Hex colour guard: exactly #rrggbb. */
    public const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * The full set of card elements the builder can order + toggle. DOM/visual order is driven by
     * the stored `elements` array; unknown keys are dropped by the accessor.
     *
     * @var list<string>
     */
    public const ELEMENT_KEYS = [
        'eyebrow',
        'badge',
        'timer',
        'image',
        'headline',
        'product_name',
        'subcopy',
        'price',
        'save',
        'trust',
        'cta',
        'decline',
        'disclosure',
    ];

    /**
     * Money + legal safety BY CONSTRUCTION: these three are always present + always enabled in the
     * resolved element list, regardless of what the stored JSON says. A merchant cannot remove the
     * price, the buy button, or the consent disclosure.
     *
     * @var list<string>
     */
    public const LOCKED_ELEMENTS = ['price', 'cta', 'disclosure'];

    /**
     * The polished out-of-box element set (order + on/off). Beauty is requirement #1 and there is
     * no data risk (money is locked, product is server-supplied), so the default is the full
     * editorial card; only badge + timer are off by default.
     *
     * @var list<array{key: string, enabled: bool}>
     */
    public const DEFAULT_ELEMENTS = [
        ['key' => 'eyebrow', 'enabled' => true],
        ['key' => 'badge', 'enabled' => false],
        ['key' => 'timer', 'enabled' => false],
        ['key' => 'image', 'enabled' => true],
        ['key' => 'headline', 'enabled' => true],
        ['key' => 'product_name', 'enabled' => true],
        ['key' => 'subcopy', 'enabled' => true],
        ['key' => 'price', 'enabled' => true],
        ['key' => 'save', 'enabled' => true],
        ['key' => 'trust', 'enabled' => true],
        ['key' => 'cta', 'enabled' => true],
        ['key' => 'decline', 'enabled' => true],
        ['key' => 'disclosure', 'enabled' => true],
    ];

    // === Defaults (a fresh row = the beautiful house card) ===
    public const DEFAULT_THEME = self::THEME_LIGHT;
    public const DEFAULT_ACCENT = '#000000';
    public const DEFAULT_ACCENT_TEXT = '#ffffff';
    public const DEFAULT_BUTTON = self::BUTTON_SOLID;
    public const DEFAULT_RADIUS = self::RADIUS_SHARP;
    public const DEFAULT_SHADOW = self::SHADOW_SOFT;
    public const DEFAULT_FONT = self::FONT_HEEBO;
    public const DEFAULT_LAYOUT = self::LAYOUT_STACKED;
    public const DEFAULT_RATIO = self::RATIO_NATURAL;
    public const DEFAULT_DECLINE = self::DECLINE_LINK;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'elements' => 'array',
        ];
    }

    /** The row for the CURRENT tenant, created with the house defaults on first read. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'theme_mode' => self::DEFAULT_THEME,
                'accent_color' => self::DEFAULT_ACCENT,
                'accent_text_color' => self::DEFAULT_ACCENT_TEXT,
                'button_style' => self::DEFAULT_BUTTON,
                'corner_radius' => self::DEFAULT_RADIUS,
                'card_shadow' => self::DEFAULT_SHADOW,
                'theme_font' => self::DEFAULT_FONT,
                'layout' => self::DEFAULT_LAYOUT,
                'image_ratio' => self::DEFAULT_RATIO,
                'decline_style' => self::DEFAULT_DECLINE,
                'elements' => self::DEFAULT_ELEMENTS,
                'eyebrow_text' => null,
                'badge_text' => null,
                'trust_text' => null,
            ],
        );
    }

    // === Typed accessors + guards (never trust a stored/merchant value) ===

    public function themeMode(): string
    {
        return $this->oneOf($this->theme_mode, self::THEME_MODES, self::DEFAULT_THEME);
    }

    public function accentColor(): string
    {
        return $this->hexOr($this->accent_color, self::DEFAULT_ACCENT);
    }

    public function accentTextColor(): string
    {
        return $this->hexOr($this->accent_text_color, self::DEFAULT_ACCENT_TEXT);
    }

    public function buttonStyle(): string
    {
        return $this->oneOf($this->button_style, self::BUTTON_STYLES, self::DEFAULT_BUTTON);
    }

    public function cornerRadius(): string
    {
        return $this->oneOf($this->corner_radius, self::CORNER_RADII, self::DEFAULT_RADIUS);
    }

    /** The CTA border-radius in px, derived from the corner_radius token. */
    public function cornerRadiusPx(): int
    {
        return self::RADIUS_PX[$this->cornerRadius()];
    }

    public function cardShadow(): string
    {
        return $this->oneOf($this->card_shadow, self::CARD_SHADOWS, self::DEFAULT_SHADOW);
    }

    public function themeFont(): string
    {
        return $this->oneOf($this->theme_font, self::FONTS, self::DEFAULT_FONT);
    }

    public function layout(): string
    {
        return $this->oneOf($this->layout, self::LAYOUTS, self::DEFAULT_LAYOUT);
    }

    public function imageRatio(): string
    {
        return $this->oneOf($this->image_ratio, self::IMAGE_RATIOS, self::DEFAULT_RATIO);
    }

    public function declineStyle(): string
    {
        return $this->oneOf($this->decline_style, self::DECLINE_STYLES, self::DEFAULT_DECLINE);
    }

    /** Eyebrow copy, or null when blank (→ the card falls back to the localized default). */
    public function eyebrowText(): ?string
    {
        return $this->trimmedOrNull($this->eyebrow_text, 48);
    }

    public function badgeText(): ?string
    {
        return $this->trimmedOrNull($this->badge_text, 48);
    }

    public function trustText(): ?string
    {
        return $this->trimmedOrNull($this->trust_text, 80);
    }

    /**
     * The resolved, ordered element list — the SINGLE guard that makes the builder safe:
     *   1. keep only known keys (ELEMENT_KEYS), coercing `enabled` to bool;
     *   2. dedupe by key (first wins);
     *   3. force EVERY locked element present + enabled (append if missing).
     *
     * A caller can send garbage, drop the price, or disable the CTA — the price, buy button and
     * disclosure still render. Falls back to DEFAULT_ELEMENTS when the column is empty/malformed.
     *
     * @return list<array{key: string, enabled: bool}>
     */
    public function elements(): array
    {
        $raw = is_array($this->elements) ? $this->elements : [];

        $resolved = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! isset($row['key'])) {
                continue;
            }
            $key = (string) $row['key'];
            if (! in_array($key, self::ELEMENT_KEYS, true) || isset($resolved[$key])) {
                continue;
            }
            $resolved[$key] = [
                'key' => $key,
                'enabled' => (bool) ($row['enabled'] ?? true),
            ];
        }

        // Empty / all-garbage → the polished default set.
        if ($resolved === []) {
            $resolved = [];
            foreach (self::DEFAULT_ELEMENTS as $row) {
                $resolved[$row['key']] = $row;
            }
        }

        // Force the locked elements present + enabled (append any that were removed).
        foreach (self::LOCKED_ELEMENTS as $key) {
            $resolved[$key] = ['key' => $key, 'enabled' => true];
        }

        return array_values($resolved);
    }

    // === Private guards ===

    /** @param  list<string>  $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function hexOr(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match(self::HEX_PATTERN, $value) === 1 ? strtolower($value) : $fallback;
    }

    private function trimmedOrNull(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
