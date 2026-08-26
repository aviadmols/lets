<?php

namespace App\Domain\Campaigns\Email\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One passwordless "enter my account" link, as minted into one email.
 *
 * THE ROW HOLDS THE HASH, NEVER THE TOKEN. The raw token exists only in the
 * email and the URL; this row cannot be replayed from a database read.
 *
 * REUSABLE INSIDE A WINDOW ANCHORED AT THE FIRST CLICK. The same person opens
 * the email on their phone in the morning and their laptop at night, and
 * "already used" between those two moments reads as a broken shop — so the
 * first consume() stamps `consumed_at` (the anchor, atomically: two racing
 * first clicks stamp once) and MOVES `expires_at` to first-use + the
 * campaign's TTL; every later consume() inside that window signs in again and
 * bumps the use counters. What ends a link: the window running out, a
 * per-token revoke, or the campaign-wide revocation switch — the merchant's
 * one lever that kills every link in a sent email at once, which is also why
 * reuse is acceptable: a leaked link is stoppable the moment it is known.
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
            'last_used_at' => 'datetime',
            'use_count' => 'integer',
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

    /**
     * Still usable at this instant? (GET renders on this; POST signs in with
     * consume().) A first click has already MOVED expires_at to its own window,
     * so one comparison covers both the unclicked and the clicked life of a
     * link — being consumed no longer ends it.
     */
    public function isUsable(CarbonInterface $now): bool
    {
        if ($this->revoked_at !== null) {
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
     * Use the token — sign this visit in, and anchor the reuse window if this
     * is the first.
     *
     * Two conditional UPDATEs whose WHEREs repeat every usability condition
     * rather than trusting a prior isUsable() read (two POSTs in the same
     * instant both pass the read; only the database can arbitrate):
     *
     *   1. FIRST use: stamp consumed_at (the anchor) and MOVE expires_at to
     *      now + the campaign's TTL — "valid for X days from the first click",
     *      whatever was left of the click-by window. The ip/ua audit fields
     *      record this first opener.
     *   2. REPEAT use: inside that window, bump last_used_at + use_count. The
     *      phone in the morning, the laptop at night.
     *
     * Racing first clicks: exactly one runs branch 1; the loser falls through
     * to branch 2 and signs in too — both are the same person's devices, and
     * both visits are counted.
     */
    public function consume(?string $ipHash, ?string $userAgent): bool
    {
        if ($this->campaignRevoked()) {
            return false;
        }

        $first = static::query()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'consumed_at' => now(),
                'expires_at' => now()->addHours($this->reuseWindowHours()),
                'use_count' => 1,
                'last_used_at' => now(),
                'consumed_ip_hash' => $ipHash,
                'consumed_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'updated_at' => now(),
            ]) === 1;

        $moved = $first || static::query()
            ->whereKey($this->getKey())
            ->whereNotNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'use_count' => DB::raw('use_count + 1'),
                'last_used_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    /** How long a link stays live from its FIRST click — the campaign's own TTL. */
    private function reuseWindowHours(): int
    {
        $campaign = $this->relationLoaded('campaign')
            ? $this->campaign
            : ($this->email_campaign_id !== null
                ? EmailCampaign::acrossAllTenants()->whereKey($this->email_campaign_id)->first()
                : null);

        return $campaign?->loginTtlHours()
            ?? max(1, (int) config('campaigns.login_link_ttl_hours', 168));
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
