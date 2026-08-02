<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One membership tier: a threshold on lifetime spend, and what passing it earns
 * (a points multiplier, a once-ever entry bonus, and the perk lines the
 * customer-facing comparison table shows).
 *
 * Tenant-scoped by BelongsToShop. Merchant-typed values are read through guards —
 * the multiplier especially, because it multiplies every future award.
 */
class LoyaltyTier extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'loyalty_tiers';

    /** Icon keys the customer page knows how to draw (inline SVG, no assets). */
    public const ICON_SPARK = 'spark';
    public const ICON_GLOW = 'glow';
    public const ICON_SHINE = 'shine';
    public const ICON_STAR = 'star';
    public const ICON_CROWN = 'crown';
    public const ICON_GEM = 'gem';
    public const ICON_HEART = 'heart';
    public const ICONS = [
        self::ICON_SPARK, self::ICON_GLOW, self::ICON_SHINE,
        self::ICON_STAR, self::ICON_CROWN, self::ICON_GEM, self::ICON_HEART,
    ];

    /** A multiplier outside these bounds is a typo, not a policy. */
    public const MIN_MULTIPLIER = 0.0;
    public const MAX_MULTIPLIER = 10.0;

    /** Perk lines: enough for a readable table, not a manifesto. */
    public const MAX_PERKS = 12;
    public const MAX_PERK_LENGTH = 80;

    public const DEFAULT_COLOR = '#7746ec';
    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'min_spend' => 'decimal:2',
            'points_multiplier' => 'decimal:2',
            'entry_bonus_points' => 'integer',
            'position' => 'integer',
            'perks' => 'array',
        ];
    }

    /** Lowest threshold first — the order a customer climbs them in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('min_spend')->orderBy('position');
    }

    // === Guarded reads ===

    public function multiplier(): float
    {
        $value = (float) $this->points_multiplier;

        return max(self::MIN_MULTIPLIER, min(self::MAX_MULTIPLIER, $value));
    }

    public function minSpend(): float
    {
        return max(0, round((float) $this->min_spend, 2));
    }

    public function entryBonusPoints(): int
    {
        return max(0, min(MerchantLoyaltySettings::MAX_BONUS_POINTS, (int) $this->entry_bonus_points));
    }

    public function color(): string
    {
        $value = is_string($this->color) ? trim($this->color) : '';

        return preg_match(self::HEX_PATTERN, $value) === 1 ? strtolower($value) : self::DEFAULT_COLOR;
    }

    public function icon(): string
    {
        $value = is_string($this->icon) ? $this->icon : '';

        return in_array($value, self::ICONS, true) ? $value : self::ICON_SPARK;
    }

    /** @return list<string> the perk lines, trimmed and bounded. */
    public function perkLines(): array
    {
        $out = [];

        foreach ((array) ($this->perks ?? []) as $perk) {
            $line = is_string($perk) ? trim($perk) : '';
            if ($line === '') {
                continue;
            }
            $out[] = mb_substr($line, 0, self::MAX_PERK_LENGTH);
            if (count($out) >= self::MAX_PERKS) {
                break;
            }
        }

        return $out;
    }
}
