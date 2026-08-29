<?php

namespace App\Domain\Brand\Models;

use App\Domain\Campaigns\Studio\NewsletterDocument;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * One shop's Design DNA. Nothing reads it before the merchant approved it,
 * and everything that reads it goes through dnaGlobals() — the guarded
 * translation into the studio's own vocabulary, so a poisoned or stale DNA
 * value can never reach a document unguarded.
 */
class ShopBrandProfile extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'shop_brand_profiles';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'dna' => 'array',
            'pages' => 'array',
            'fetched_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** Same raw read as every status() here — the column/method shadowing trap. */
    public function status(): string
    {
        return (string) ($this->attributes['status'] ?? self::STATUS_PENDING);
    }

    public function isApproved(): bool
    {
        return $this->status() === self::STATUS_APPROVED;
    }

    /**
     * The DNA as studio GLOBALS — only the keys the document speaks, every
     * color re-validated, the font checked against the whitelist. An approved
     * DNA row from an older release (or a poisoned one) narrows to nothing
     * harmful here.
     *
     * @return array<string, mixed>
     */
    public function dnaGlobals(): array
    {
        $dna = (array) ($this->dna ?? []);
        $colors = (array) ($dna['colors'] ?? []);

        $out = [];

        foreach ([
            'background_color', 'content_background', 'text_color',
            'link_color', 'button_color', 'button_text_color',
        ] as $key) {
            $hex = NewsletterDocument::hexOr($colors[$key] ?? null, '');
            if ($hex !== '') {
                $out[$key] = $hex;
            }
        }

        $font = (string) ($dna['font_family'] ?? '');
        if (in_array($font, NewsletterDocument::FONTS, true)) {
            $out['font_family'] = $font;
        }

        return $out;
    }

    /** The tone note the prompts carry — plain text, capped. */
    public function tone(): string
    {
        return mb_substr(trim((string) (((array) ($this->dna ?? []))['tone'] ?? '')), 0, 500);
    }

    /** The row for one shop, read by id — correct on a worker with no tenant. */
    public static function forShop(int $shopId): ?self
    {
        return static::acrossAllTenants()->where('shop_id', $shopId)->first();
    }
}
