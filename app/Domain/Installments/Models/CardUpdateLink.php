<?php

namespace App\Domain\Installments\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One durable "update your card" link, as handed to one customer.
 *
 * THE ROW HOLDS THE HASH, NEVER THE TOKEN. The raw token exists only in the URL
 * the merchant copied, emailed or texted; this row cannot be replayed from a
 * database read into somebody's payment page.
 *
 * IT IS NOT THE PAYPLUS PAGE. A PayPlus re-vault page is minted for a moment and
 * expires on their side, so an emailed one is dead before the customer opens the
 * mail. This link is ours and lasts for days; clicking it mints the PayPlus page
 * at that instant. That is the whole reason the row exists.
 *
 * WHAT ENDS IT: the window running out, a revoke (per link, or every open link on
 * the plan), or the card actually being replaced. `completed_at` is stamped by the
 * PayPlus callback, so the merchant's status line says "card updated" because the
 * card was updated — not because somebody opened a page.
 */
class CardUpdateLink extends Model
{
    use BelongsToShop;
    use Prunable;

    // === CONSTANTS ===
    protected $table = 'card_update_links';

    /** 48 random alphanumerics (Str::random's alphabet) — the campaign link's shape. */
    public const TOKEN_LENGTH = 48;

    /** Worth a database round trip only if it could be one of ours. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{32,128}$/';

    /** How the merchant sent it. */
    public const CHANNEL_COPY = 'copy';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNELS = [self::CHANNEL_COPY, self::CHANNEL_EMAIL, self::CHANNEL_SMS];

    /** The lifetimes the form offers, in days. */
    public const TTL_OPTIONS_DAYS = [1, 3, 7, 14, 30];

    public const DEFAULT_TTL_DAYS = 7;

    public const MAX_TTL_DAYS = 30;

    /** Long-dead rows carry a customer reference and earn nothing by staying. */
    public const PRUNE_AFTER_DAYS = 90;

    /** The hash is not mass-assignable, and neither is any lifecycle stamp. */
    protected $guarded = ['id', 'shop_id', 'token_hash', 'clicked_at', 'completed_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'clicked_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    /** sha256 of the raw token — the only form that is ever stored. */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Can this link still do anything?
     *
     * A COMPLETED link is spent: the card has been replaced, and re-opening the
     * page would put a second card on the plan for somebody who thinks they are
     * confirming the first.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->completed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /** The merchant-facing state, one word. Drives the status line and its badge. */
    public function state(): string
    {
        return match (true) {
            $this->completed_at !== null => 'completed',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at === null || $this->expires_at->isPast() => 'expired',
            $this->clicked_at !== null => 'opened',
            default => 'sent',
        };
    }

    /** Stamp the first open. Idempotent: a second visit is not a second click. */
    public function markClicked(): void
    {
        if ($this->clicked_at === null) {
            $this->forceFill(['clicked_at' => now()])->save();
        }
    }

    /** The card was actually replaced through this link. */
    public function markCompleted(): void
    {
        if ($this->completed_at === null) {
            $this->forceFill(['completed_at' => now()])->save();
        }
    }

    /** Kill it. Idempotent, and never un-completes a link that already worked. */
    public function revoke(): bool
    {
        if ($this->revoked_at !== null || $this->completed_at !== null) {
            return false;
        }

        $this->forceFill(['revoked_at' => now()])->save();

        return true;
    }

    /** Every link on this plan that could still be clicked. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->whereNull('completed_at')
            ->where('expires_at', '>', now());
    }

    /** Expired/spent rows older than the window carry a customer reference for nothing. */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }
}
