<?php

namespace App\Domain\Campaigns\Email\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email campaign: the message, who it goes to, and the run.
 *
 * Every merchant-typed value is read back through a GUARD, never raw — the
 * audience bag especially, because it decides whose inbox this lands in. An
 * unreadable source list narrows nothing rather than widening to everyone; an
 * unreadable status list falls back to "active + paused", the same default the
 * account offers use.
 *
 * `status` is guarded from mass assignment and moves only through the sender:
 * claimForSending() is the one atomic draft|scheduled → sending step, and
 * settleStatus() is what closes the run.
 */
class EmailCampaign extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'email_campaigns';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_SENDING,
        self::STATUS_SENT, self::STATUS_CANCELLED,
    ];

    /** The states in which the merchant may still change the message or the audience. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_SCHEDULED];

    /** Who can be in the audience at all. Empty = all three. */
    public const SOURCE_SUBSCRIBERS = 'subscribers';

    public const SOURCE_PURCHASERS = 'purchasers';

    public const SOURCE_LOYALTY_MEMBERS = 'loyalty_members';

    public const SOURCES = [self::SOURCE_SUBSCRIBERS, self::SOURCE_PURCHASERS, self::SOURCE_LOYALTY_MEMBERS];

    /** The audience filter keys, in the order the admin form draws them. */
    public const AUDIENCE_KEYS = ['sources', 'statuses', 'frequencies', 'product_ids', 'loyalty_tier_ids'];

    /** The statuses a campaign targets when the merchant named none. */
    public const DEFAULT_AUDIENCE_STATUSES = [
        PlanStatus::ACTIVE->value,
        PlanStatus::PAUSED->value,
    ];

    public const MAX_AUDIENCE_PRODUCTS = 100;

    public const MAX_AUDIENCE_TIERS = 50;

    /** The two ways the merchant may write the body. */
    public const EDITOR_VISUAL = 'visual';

    public const EDITOR_HTML = 'html';

    public const EDITORS = [self::EDITOR_VISUAL, self::EDITOR_HTML];

    /**
     * The tokens a merchant may write into the subject and body. Substituted by
     * TemplateRenderer (strtr) — the same wall every mail template stands behind.
     */
    public const PLACEHOLDERS = ['customer_name', 'customer_email', 'business_name', 'account_login_url', 'unsubscribe_url'];

    public const TOKEN_LOGIN = '{account_login_url}';

    public const TOKEN_UNSUBSCRIBE = '{unsubscribe_url}';

    /** The link lifetimes the form offers, in hours (1 day … 2 weeks). */
    public const LOGIN_TTL_OPTIONS_HOURS = [24, 72, 168, 336];

    public const MAX_NAME = 120;

    public const MAX_SUBJECT = 255;

    public const MAX_BODY = 200_000;

    /** shop_id is auto-stamped; status moves only through the sender. */
    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'is_marketing' => 'boolean',
            'login_link_ttl_hours' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'login_links_revoked_at' => 'datetime',
            'recipients_total' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    // === Relations ===

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'email_campaign_id');
    }

    public function loginTokens(): HasMany
    {
        return $this->hasMany(CustomerLoginToken::class, 'email_campaign_id');
    }

    // === Guarded reads ===

    /**
     * The status, guarded.
     *
     * Reads `$this->attributes` DIRECTLY rather than `$this->status`. A method
     * that shares a column's name shadows it: Eloquent's magic getter only
     * returns the attribute when it is loaded, and on a half-built model — one
     * Filament has just created, whose guarded `status` came from the database
     * default and is not in memory yet — it falls through to relation
     * resolution and fatals. The raw read is correct in both states.
     */
    public function status(): string
    {
        $value = $this->attributes['status'] ?? null;

        return is_string($value) && in_array($value, self::STATUSES, true) ? $value : self::STATUS_DRAFT;
    }

    public function isEditable(): bool
    {
        return in_array($this->status(), self::EDITABLE_STATUSES, true);
    }

    public function isSending(): bool
    {
        return $this->status() === self::STATUS_SENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status() === self::STATUS_CANCELLED;
    }

    /** Same raw read as status(), for the same shadowing reason. */
    public function editorMode(): string
    {
        $value = $this->attributes['editor_mode'] ?? null;

        return is_string($value) && in_array($value, self::EDITORS, true) ? $value : self::EDITOR_VISUAL;
    }

    /**
     * The audience filters, sanitised. Every list is INCLUSIVE and an empty list
     * means "any" — so a filter that loses all its values stops narrowing rather
     * than hiding the campaign from everyone.
     *
     * The raw read again (see status()): this method shares its name with the
     * column it reads, so the magic getter cannot be trusted on a model whose
     * attributes are not fully loaded.
     *
     * @return array{sources: list<string>, statuses: list<string>, frequencies: list<string>, product_ids: list<string>, loyalty_tier_ids: list<int>}
     */
    public function audience(): array
    {
        $raw = $this->attributes['audience'] ?? null;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return self::cleanAudience(is_array($raw) ? $raw : []);
    }

    /**
     * The same guard, over a raw bag — the form's "how many would get this"
     * count runs over unsaved state, so the cleaning cannot live on the row alone.
     *
     * @param  array<string, mixed>  $raw
     * @return array{sources: list<string>, statuses: list<string>, frequencies: list<string>, product_ids: list<string>, loyalty_tier_ids: list<int>}
     */
    public static function cleanAudience(array $raw): array
    {
        return [
            'sources' => self::oneOfList($raw['sources'] ?? [], self::SOURCES),
            'statuses' => self::enumValues($raw['statuses'] ?? [], PlanStatus::class)
                ?: self::DEFAULT_AUDIENCE_STATUSES,
            'frequencies' => self::enumValues($raw['frequencies'] ?? [], BillingFrequency::class),
            'product_ids' => self::stringIds($raw['product_ids'] ?? [], self::MAX_AUDIENCE_PRODUCTS),
            'loyalty_tier_ids' => self::intIds($raw['loyalty_tier_ids'] ?? [], self::MAX_AUDIENCE_TIERS),
        ];
    }

    /** The link lifetime THIS campaign promises, capped by the platform. */
    public function loginTtlHours(): int
    {
        $max = max(1, (int) config('campaigns.max_login_ttl_hours', 336));
        $default = max(1, (int) config('campaigns.login_link_ttl_hours', 168));

        $own = (int) ($this->login_link_ttl_hours ?? 0);

        return min($max, $own > 0 ? $own : $default);
    }

    /** Does the body mention this token at all? Decides whether a credential is minted. */
    public function bodyHasToken(string $token): bool
    {
        return str_contains((string) $this->body_html, $token)
            || str_contains((string) $this->subject, $token);
    }

    public function isMarketing(): bool
    {
        return (bool) $this->is_marketing;
    }

    // === Transitions (the sender's vocabulary) ===

    /**
     * Take the campaign for sending — ONE atomic draft|scheduled → sending move,
     * so two merchants clicking Send at the same moment, or a click racing the
     * scheduler, produce one run and not two.
     */
    public function claimForSending(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', self::EDITABLE_STATUSES)
            ->update([
                'status' => self::STATUS_SENDING,
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    public function markScheduled(\DateTimeInterface $at): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_DRAFT)
            ->update(['status' => self::STATUS_SCHEDULED, 'scheduled_at' => $at, 'updated_at' => now()]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    public function markUnscheduled(): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_SCHEDULED)
            ->update(['status' => self::STATUS_DRAFT, 'scheduled_at' => null, 'updated_at' => now()]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    /** Stop a scheduled or in-flight run. Jobs still queued see it and skip. */
    public function markCancelled(): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_SENDING])
            ->update(['status' => self::STATUS_CANCELLED, 'cancelled_at' => now(), 'updated_at' => now()]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    /**
     * Settle the run from its recipients' terminal states. Called when each job
     * finishes; when nothing is pending or in flight the campaign is SENT —
     * failures and skips are in the counts, not hidden behind a rounder word.
     */
    public function settleStatus(): void
    {
        $this->refreshCounts();

        if ($this->status() !== self::STATUS_SENDING) {
            return;
        }

        $open = $this->recipients()
            ->whereIn('status', [EmailCampaignRecipient::STATUS_PENDING, EmailCampaignRecipient::STATUS_SENDING])
            ->exists();

        if ($open) {
            return;
        }

        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_SENDING)
            ->update(['status' => self::STATUS_SENT, 'sent_at' => now(), 'updated_at' => now()]);

        $this->refresh();
    }

    /** The cached counts, recomputed from the recipients table. */
    public function refreshCounts(): void
    {
        $counts = $this->recipients()
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $this->forceFill([
            'recipients_total' => (int) $counts->sum(),
            'sent_count' => (int) ($counts[EmailCampaignRecipient::STATUS_SENT] ?? 0),
            'failed_count' => (int) ($counts[EmailCampaignRecipient::STATUS_FAILED] ?? 0),
            'skipped_count' => (int) ($counts[EmailCampaignRecipient::STATUS_SKIPPED] ?? 0),
        ])->save();
    }

    // === Private guards ===

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function oneOfList(mixed $raw, array $allowed): array
    {
        $out = [];
        foreach ((array) $raw as $value) {
            if (is_string($value) && in_array($value, $allowed, true) && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Keep only the values that are real cases of $enum, deduped, in order.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private static function enumValues(mixed $raw, string $enum): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = (string) $value;

            if ($enum::tryFrom($value) !== null && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Platform product ids, as STRINGS — never cast to int (a Shopify id is not
     * necessarily numeric) and deduped by VALUE, not array key (a "2666" key is
     * silently the integer 2666, and the comparison downstream is strict).
     *
     * @return list<string>
     */
    private static function stringIds(mixed $raw, int $max): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function intIds(mixed $raw, int $max): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }
            $value = (int) $value;
            if ($value > 0 && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }
}
