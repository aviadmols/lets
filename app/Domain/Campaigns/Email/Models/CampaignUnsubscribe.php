<?php

namespace App\Domain\Campaigns\Email\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * The per-shop suppression list: addresses that asked not to receive campaigns.
 *
 * Tenant-scoped by BelongsToShop; one row per address; written once and never
 * edited. Transactional plan mail ignores this list — it is about marketing.
 */
class CampaignUnsubscribe extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'campaign_unsubscribes';

    /** How the request arrived. */
    public const SOURCE_LINK = 'link';

    public const SOURCE_ONE_CLICK = 'one_click';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [self::SOURCE_LINK, self::SOURCE_ONE_CLICK, self::SOURCE_ADMIN];

    protected $guarded = ['id', 'shop_id'];

    /** The one spelling every reader and writer compares: trimmed, lower-cased. */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Is this address on the bound shop's list? */
    public static function isSuppressed(string $email): bool
    {
        $email = self::normalise($email);
        if ($email === '') {
            return false;
        }

        return static::query()->where('email', $email)->exists();
    }

    /**
     * Every suppressed address of the bound shop, lower-cased, as a lookup set.
     *
     * @return array<string, true>
     */
    public static function suppressedSet(): array
    {
        return static::query()->pluck('email')->flip()->map(static fn (): bool => true)->all();
    }

    /** Record a request. Idempotent — a second click is still one row. */
    public static function record(string $email, ?int $campaignId, string $source, ?string $ipHash = null): self
    {
        $email = self::normalise($email);
        $source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_LINK;

        return static::query()->firstOrCreate(
            ['email' => $email],
            ['email_campaign_id' => $campaignId, 'source' => $source, 'ip_hash' => $ipHash],
        );
    }
}
