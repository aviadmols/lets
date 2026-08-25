<?php

namespace App\Domain\Campaigns\Email\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One passwordless "enter my account" link, as minted into one email.
 *
 * THE ROW HOLDS THE HASH, NEVER THE TOKEN. The raw token exists only in the
 * email and the URL; this row cannot be replayed from a database read.
 *
 * SINGLE USE, ATOMICALLY. consume() is one conditional UPDATE and must move
 * exactly one row — a second click, a forwarded copy, a scanner's prefetch, all
 * find nothing to spend. A campaign-level revocation (`login_links_revoked_at`
 * on the campaign) is honoured too, so the merchant has one switch that kills
 * every link in a sent email at once.
 */
class CustomerLoginToken extends Model
{
    use BelongsToShop;
    use Prunable;

    // === CONSTANTS ===
    protected $table = 'customer_login_tokens';

    /** 48 random alphanumeric characters (Str::random's alphabet, ~286 bits). */
    public const TOKEN_LENGTH = 48;

    /** The shape a token must have before it is worth a database round trip. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{32,128}$/';

    public const PLATFORM_SHOPIFY = Shop::PLATFORM_SHOPIFY;

    public const PLATFORM_WOOCOMMERCE = Shop::PLATFORM_WOOCOMMERCE;

    /** The raw token is never stored; the hash is not mass-assignable either. */
    protected $guarded = ['id', 'shop_id', 'token_hash', 'consumed_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignRecipient::class, 'recipient_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /** sha256 of the raw token — the one derivation every reader and writer uses. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Still spendable at this instant? (GET renders on this; POST spends with consume().) */
    public function isUsable(CarbonInterface $now): bool
    {
        if ($this->consumed_at !== null || $this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at === null || $now->greaterThanOrEqualTo($this->expires_at)) {
            return false;
        }

        return ! $this->campaignRevoked();
    }

    /** Did the merchant pull every link in this campaign? */
    public function campaignRevoked(): bool
    {
        if ($this->email_campaign_id === null) {
            return false;
        }

        $campaign = $this->relationLoaded('campaign')
            ? $this->campaign
            : EmailCampaign::acrossAllTenants()->whereKey($this->email_campaign_id)->first();

        return $campaign?->login_links_revoked_at !== null;
    }

    /**
     * Spend the token — ONE conditional UPDATE that must move exactly one row.
     *
     * The WHERE repeats every usability condition rather than trusting a prior
     * isUsable() read: two POSTs in the same instant both pass the read, and only
     * the database can make sure only one of them wins.
     */
    public function consume(?string $ipHash, ?string $userAgent): bool
    {
        if ($this->campaignRevoked()) {
            return false;
        }

        $moved = static::query()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'consumed_at' => now(),
                'consumed_ip_hash' => $ipHash,
                'consumed_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'updated_at' => now(),
            ]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    /** Pull this one token (a send failure, a support request). */
    public function revoke(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        $this->refresh();
    }

    /** Spent or expired rows are kept a while for abuse reports, then go. */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('campaigns.token_prune_days', 30));

        return static::acrossAllTenants()->where('expires_at', '<', now()->subDays($days));
    }
}
