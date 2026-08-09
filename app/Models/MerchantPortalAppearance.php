<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The shopper's personal area: which sections appear, in what order, and how the
 * whole thing looks — one row per shop.
 *
 * Every merchant-set value is read back through a GUARD, never raw, exactly as
 * MerchantUpsellAppearance and MerchantLoyaltySettings do: these values are
 * interpolated into a page on the merchant's own storefront, so an unvalidated
 * colour or URL is a hostile-content vector, and an unvalidated section key is a
 * blank screen.
 *
 * There is deliberately NO font setting. The area inherits the theme's typography —
 * that is the whole point of rendering it natively instead of in an iframe.
 */
class MerchantPortalAppearance extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'merchant_portal_appearance';

    /** Sections the merchant can show, hide and reorder. */
    public const SECTION_WELCOME = 'welcome';
    public const SECTION_SUBSCRIPTIONS = 'subscriptions';
    public const SECTION_UPCOMING = 'upcoming';
    public const SECTION_BENEFITS = 'benefits';
    public const SECTION_LOYALTY = 'loyalty';
    public const SECTION_ORDERS = 'orders';
    public const SECTION_DOCUMENTS = 'documents';
    public const SECTION_PROFILE = 'profile';
    public const SECTION_ADDRESSES = 'addresses';
    public const SECTION_SUPPORT = 'support';

    public const SECTION_KEYS = [
        self::SECTION_WELCOME,
        self::SECTION_SUBSCRIPTIONS,
        self::SECTION_UPCOMING,
        self::SECTION_BENEFITS,
        self::SECTION_LOYALTY,
        self::SECTION_ORDERS,
        self::SECTION_DOCUMENTS,
        self::SECTION_PROFILE,
        self::SECTION_ADDRESSES,
        self::SECTION_SUPPORT,
    ];

    /**
     * Subscriptions is the reason this area exists: a shopper who cannot reach
     * their own subscription has no way to pause or cancel it, which is a support
     * burden for the merchant and a dark pattern for the shopper. It cannot be
     * hidden, and it cannot be reordered out of existence.
     */
    public const LOCKED_SECTIONS = [self::SECTION_SUBSCRIPTIONS];

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';
    public const THEME_AUTO = 'auto';
    public const THEME_MODES = [self::THEME_LIGHT, self::THEME_DARK, self::THEME_AUTO];

    public const RADIUS_SHARP = 'sharp';
    public const RADIUS_SOFT = 'soft';
    public const RADIUS_PILL = 'pill';
    public const CORNER_RADII = [self::RADIUS_SHARP, self::RADIUS_SOFT, self::RADIUS_PILL];

    public const DENSITY_COMPACT = 'compact';
    public const DENSITY_COMFORTABLE = 'comfortable';
    public const DENSITIES = [self::DENSITY_COMPACT, self::DENSITY_COMFORTABLE];

    public const CARD_FLAT = 'flat';
    public const CARD_OUTLINED = 'outlined';
    public const CARD_RAISED = 'raised';
    public const CARD_STYLES = [self::CARD_FLAT, self::CARD_OUTLINED, self::CARD_RAISED];

    /**
     * The language the area is written in. AUTO follows the store — the
     * WordPress site language the plugin sends with every call — which is the
     * right default for a merchant who never opens this setting, and the only
     * one that stays right when they translate their shop.
     */
    public const LOCALE_AUTO = 'auto';
    public const LOCALE_HE = 'he';
    public const LOCALE_EN = 'en';
    public const PAGE_LOCALES = [self::LOCALE_AUTO, self::LOCALE_HE, self::LOCALE_EN];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_BOTH = 'both';
    public const LOGIN_CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_BOTH];

    /** The side rail. Three is enough to be useful and few enough to stay tidy. */
    public const BANNER_SLOTS = 3;

    public const DEFAULT_ACCENT = '#111827';
    public const DEFAULT_ACCENT_TEXT = '#ffffff';

    /** Radius enum → the px the stylesheet actually uses. */
    public const RADIUS_PX = [
        self::RADIUS_SHARP => '0px',
        self::RADIUS_SOFT => '14px',
        self::RADIUS_PILL => '999px',
    ];

    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';
    private const MAX_HEADING = 80;
    private const MAX_SUBTEXT = 300;
    private const MAX_URL = 500;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'banners' => 'array',
            'login_code_enabled' => 'boolean',
        ];
    }

    /** The row for the CURRENT tenant, created with the house defaults on first read. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'accent_color' => self::DEFAULT_ACCENT,
                'accent_text_color' => self::DEFAULT_ACCENT_TEXT,
                'theme_mode' => self::THEME_LIGHT,
                'corner_radius' => self::RADIUS_SOFT,
                'density' => self::DENSITY_COMFORTABLE,
                'card_style' => self::CARD_OUTLINED,
                'sections' => self::defaultSections(),
                'banners' => [],
                'login_code_enabled' => false,
                'login_code_channel' => self::CHANNEL_EMAIL,
            ],
        );
    }

    /** Everything on, in the order a shopper reads it. */
    public static function defaultSections(): array
    {
        return array_map(
            static fn (string $key): array => ['key' => $key, 'enabled' => true],
            self::SECTION_KEYS,
        );
    }

    // === Sections ===

    /**
     * The merchant's ordered section list, sanitised: unknown keys dropped,
     * duplicates collapsed (first wins), every locked section forced present and
     * enabled. Garbage or an empty list falls back to the defaults, because an
     * empty personal area is never what anyone meant.
     *
     * @return list<array{key: string, enabled: bool}>
     */
    public function sections(): array
    {
        $out = [];
        $seen = [];

        foreach ((array) ($this->sections ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = is_string($row['key'] ?? null) ? $row['key'] : '';
            if (! in_array($key, self::SECTION_KEYS, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'enabled' => in_array($key, self::LOCKED_SECTIONS, true) || (bool) ($row['enabled'] ?? false),
            ];
        }

        if ($out === []) {
            return self::defaultSections();
        }

        // A locked section the merchant never had (a new release adds one) is
        // appended rather than silently missing.
        foreach (self::LOCKED_SECTIONS as $locked) {
            if (! isset($seen[$locked])) {
                $out[] = ['key' => $locked, 'enabled' => true];
            }
        }

        return $out;
    }

    /** Is a section visible? Unknown keys are not. */
    public function sectionEnabled(string $key): bool
    {
        foreach ($this->sections() as $section) {
            if ($section['key'] === $key) {
                return $section['enabled'];
            }
        }

        return false;
    }

    /** Just the visible keys, in order — what the renderer walks. */
    public function visibleSections(): array
    {
        return array_values(array_map(
            static fn (array $s): string => $s['key'],
            array_filter($this->sections(), static fn (array $s): bool => $s['enabled']),
        ));
    }

    // === Side banners ===

    /**
     * The side rail, sanitised. A banner with neither a heading nor an image is
     * not a banner. Only https survives on both the image and the link — a
     * merchant-typed `javascript:` would ship straight onto a customer page.
     *
     * @return list<array{heading: ?string, subtext: ?string, image_url: ?string, link_url: ?string}>
     */
    public function banners(): array
    {
        $out = [];

        foreach ((array) ($this->banners ?? []) as $row) {
            if (! is_array($row) || ! (bool) ($row['enabled'] ?? true)) {
                continue;
            }

            $banner = [
                'heading' => $this->trimmedOrNull($row['heading'] ?? null, self::MAX_HEADING),
                'subtext' => $this->trimmedOrNull($row['subtext'] ?? null, self::MAX_SUBTEXT),
                'image_url' => $this->httpsOrNull($row['image_url'] ?? null),
                'link_url' => $this->httpsOrNull($row['link_url'] ?? null),
            ];

            if ($banner['heading'] === null && $banner['image_url'] === null) {
                continue;
            }

            $out[] = $banner;

            if (count($out) >= self::BANNER_SLOTS) {
                break;
            }
        }

        return $out;
    }

    // === Appearance (guarded reads) ===

    public function accentColor(): string
    {
        return $this->hexOr($this->accent_color, self::DEFAULT_ACCENT);
    }

    public function accentTextColor(): string
    {
        return $this->hexOr($this->accent_text_color, self::DEFAULT_ACCENT_TEXT);
    }

    public function themeMode(): string
    {
        return $this->oneOf($this->theme_mode, self::THEME_MODES, self::THEME_LIGHT);
    }

    public function cornerRadius(): string
    {
        return $this->oneOf($this->corner_radius, self::CORNER_RADII, self::RADIUS_SOFT);
    }

    public function cornerRadiusPx(): string
    {
        return self::RADIUS_PX[$this->cornerRadius()];
    }

    public function density(): string
    {
        return $this->oneOf($this->density, self::DENSITIES, self::DENSITY_COMFORTABLE);
    }

    public function cardStyle(): string
    {
        return $this->oneOf($this->card_style, self::CARD_STYLES, self::CARD_OUTLINED);
    }

    /**
     * The merchant's language choice — one of PAGE_LOCALES, never a resolved
     * locale. AUTO is an answer, not a missing one, so the caller that knows the
     * store's own language is the one that turns it into 'he' or 'en'.
     */
    public function pageLocale(): string
    {
        return $this->oneOf($this->page_locale, self::PAGE_LOCALES, self::LOCALE_AUTO);
    }

    // === Sign-in ===

    public function loginCodeEnabled(): bool
    {
        return (bool) $this->login_code_enabled;
    }

    public function loginCodeChannel(): string
    {
        return $this->oneOf($this->login_code_channel, self::LOGIN_CHANNELS, self::CHANNEL_EMAIL);
    }

    /** Is this delivery channel one the merchant actually offers? */
    public function allowsChannel(string $channel): bool
    {
        if (! $this->loginCodeEnabled()) {
            return false;
        }

        return $this->loginCodeChannel() === self::CHANNEL_BOTH
            || $this->loginCodeChannel() === $channel;
    }

    // === Copy ===

    public function welcomeHeading(): ?string
    {
        return $this->trimmedOrNull($this->welcome_heading, self::MAX_HEADING);
    }

    public function welcomeSubtext(): ?string
    {
        return $this->trimmedOrNull($this->welcome_subtext, self::MAX_SUBTEXT);
    }

    public function supportEmail(): ?string
    {
        $value = is_string($this->support_email) ? trim($this->support_email) : '';

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    public function supportUrl(): ?string
    {
        return $this->httpsOrNull($this->support_url);
    }

    // === Private guards ===

    /** @param list<string> $allowed */
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

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /** Only https survives — a customer page must not carry a hostile scheme. */
    private function httpsOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || ! str_starts_with(strtolower($value), 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? mb_substr($value, 0, self::MAX_URL) : null;
    }
}
