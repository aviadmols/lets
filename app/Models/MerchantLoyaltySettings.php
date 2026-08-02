<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The shop's loyalty program configuration — one row per shop.
 *
 * Every merchant-set value is read back through a GUARD, never raw: an enum-ish
 * column resolves against a CONST allow-list, a colour against a hex pattern, a
 * rate through a clamp. The merchant owns the policy; this model refuses an
 * impossible one — the same discipline MerchantBillingSettings applies to
 * deposits and MerchantUpsellAppearance to colours, for the same reason: these
 * values reach a customer-facing page and (via the redemption rate) real money.
 */
class MerchantLoyaltySettings extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'merchant_loyalty_settings';

    /** Rounding applied to a computed points award. */
    public const ROUNDING_FLOOR = 'floor';
    public const ROUNDING_NEAREST = 'nearest';
    public const ROUNDINGS = [self::ROUNDING_FLOOR, self::ROUNDING_NEAREST];

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';
    public const THEME_MODES = [self::THEME_LIGHT, self::THEME_DARK];

    public const RADIUS_SHARP = 'sharp';
    public const RADIUS_SOFT = 'soft';
    public const RADIUS_PILL = 'pill';
    public const CORNER_RADII = [self::RADIUS_SHARP, self::RADIUS_SOFT, self::RADIUS_PILL];

    public const LOCALE_HE = 'he';
    public const LOCALE_EN = 'en';
    public const PAGE_LOCALES = [self::LOCALE_HE, self::LOCALE_EN];

    /** Social actions we know how to label + icon; `custom` carries its own label. */
    public const SOCIAL_FACEBOOK = 'facebook_like';
    public const SOCIAL_INSTAGRAM = 'instagram_follow';
    public const SOCIAL_TIKTOK = 'tiktok_follow';
    public const SOCIAL_CUSTOM = 'custom';
    public const SOCIAL_KEYS = [self::SOCIAL_FACEBOOK, self::SOCIAL_INSTAGRAM, self::SOCIAL_TIKTOK, self::SOCIAL_CUSTOM];

    /** Bounds. A merchant typo must not mint a million points or a free store. */
    public const MAX_POINTS_PER_CURRENCY = 1000;
    public const MAX_BONUS_POINTS = 100000;
    public const MAX_SOCIAL_ACTIONS = 8;
    public const MIN_REDEEM_RATE_POINTS = 1;

    public const DEFAULT_ACCENT = '#7746ec';
    public const DEFAULT_ACCENT_TEXT = '#ffffff';
    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'points_per_currency' => 'integer',
            'redeem_rate_points' => 'integer',
            'redeem_rate_amount' => 'decimal:2',
            'min_redeem_points' => 'integer',
            'join_bonus_points' => 'integer',
            'birthday_points' => 'integer',
            'social_actions' => 'array',
        ];
    }

    /** The row for the CURRENT tenant, created with the house defaults on first read. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'enabled' => false,
                'points_per_currency' => 1,
                'rounding' => self::ROUNDING_FLOOR,
                'redeem_rate_points' => 100,
                'redeem_rate_amount' => 10.00,
                'min_redeem_points' => 0,
                'join_bonus_points' => 0,
                'birthday_points' => 0,
                'social_actions' => [],
                'accent_color' => self::DEFAULT_ACCENT,
                'accent_text_color' => self::DEFAULT_ACCENT_TEXT,
                'theme_mode' => self::THEME_LIGHT,
                'corner_radius' => self::RADIUS_SOFT,
                'page_locale' => self::LOCALE_HE,
            ],
        );
    }

    // === Program (guarded reads) ===

    /** Points earned per 1 currency unit, before the tier multiplier. */
    public function pointsPerCurrency(): int
    {
        return max(0, min(self::MAX_POINTS_PER_CURRENCY, (int) $this->points_per_currency));
    }

    public function rounding(): string
    {
        return $this->oneOf($this->rounding, self::ROUNDINGS, self::ROUNDING_FLOOR);
    }

    /** Apply the merchant's rounding to a raw award. Never negative. */
    public function roundPoints(float $raw): int
    {
        $points = $this->rounding() === self::ROUNDING_NEAREST ? round($raw) : floor($raw);

        return max(0, (int) $points);
    }

    public function joinBonusPoints(): int
    {
        return $this->clampBonus($this->join_bonus_points);
    }

    public function birthdayPoints(): int
    {
        return $this->clampBonus($this->birthday_points);
    }

    // === Redemption ===

    /** How many points buy one `redeemRateAmount` of credit. At least 1. */
    public function redeemRatePoints(): int
    {
        return max(self::MIN_REDEEM_RATE_POINTS, (int) $this->redeem_rate_points);
    }

    /** The credit one `redeemRatePoints` chunk is worth. */
    public function redeemRateAmount(): float
    {
        return round(max(0, (float) $this->redeem_rate_amount), 2);
    }

    public function minRedeemPoints(): int
    {
        return max(0, (int) $this->min_redeem_points);
    }

    /** Can this program actually convert points to money? */
    public function redemptionAvailable(): bool
    {
        return $this->redeemRateAmount() > 0;
    }

    /**
     * The credit a points balance is worth, rounded DOWN to whole rate chunks —
     * partial chunks stay as points rather than silently rounding in either
     * party's favour.
     *
     * @return array{points: int, amount: float}
     */
    public function creditFor(int $balance): array
    {
        if ($balance <= 0 || ! $this->redemptionAvailable()) {
            return ['points' => 0, 'amount' => 0.0];
        }

        $chunks = intdiv($balance, $this->redeemRatePoints());
        $points = $chunks * $this->redeemRatePoints();

        return ['points' => $points, 'amount' => round($chunks * $this->redeemRateAmount(), 2)];
    }

    // === Social actions ===

    /**
     * The merchant's one-click actions, sanitised: known key (or `custom`), a
     * label, clamped points, and an https URL — a http/javascript URL would ship
     * a merchant-typed link straight onto a customer page.
     *
     * @return list<array{key: string, label: string, points: int, url: ?string}>
     */
    public function socialActions(): array
    {
        $out = [];

        foreach ((array) ($this->social_actions ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $this->oneOf($row['key'] ?? null, self::SOCIAL_KEYS, self::SOCIAL_CUSTOM);
            $points = $this->clampBonus($row['points'] ?? 0);
            $label = $this->trimmedOrNull($row['label'] ?? null, 60);
            $url = $this->httpsOrNull($row['url'] ?? null);

            if ($points <= 0) {
                continue; // an action worth nothing is not an action
            }

            $out[] = ['key' => $key, 'label' => $label ?? '', 'points' => $points, 'url' => $url];

            if (count($out) >= self::MAX_SOCIAL_ACTIONS) {
                break;
            }
        }

        return $out;
    }

    /** One social action by key, or null when the merchant does not offer it. */
    public function socialAction(string $key): ?array
    {
        foreach ($this->socialActions() as $action) {
            if ($action['key'] === $key) {
                return $action;
            }
        }

        return null;
    }

    // === Appearance ===

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

    public function pageLocale(): string
    {
        return $this->oneOf($this->page_locale, self::PAGE_LOCALES, self::LOCALE_HE);
    }

    public function programName(): ?string
    {
        return $this->trimmedOrNull($this->program_name, 60);
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

    private function clampBonus(mixed $value): int
    {
        return max(0, min(self::MAX_BONUS_POINTS, (int) $value));
    }

    /** Only https survives — a customer page must not carry a hostile scheme. */
    private function httpsOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || ! str_starts_with(strtolower($value), 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? mb_substr($value, 0, 500) : null;
    }
}
