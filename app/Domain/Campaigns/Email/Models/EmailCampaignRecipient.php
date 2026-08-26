<?php

namespace App\Domain\Campaigns\Email\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's enrolment in one email campaign — the idempotency wall.
 *
 * The row is CLAIMED (pending → sending, atomically) before the mail is handed to
 * the transport, so a redelivered job finds it taken and stops. `status` is
 * guarded: it moves only through the mark* helpers below.
 */
class EmailCampaignRecipient extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'email_campaign_recipients';

    /** Enrolled, nothing sent — the only state a job may act on. */
    public const STATUS_PENDING = 'pending';

    /** Handed to the transport; a second delivery of the job must stop here. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** The transport refused or broke. Retrying is the merchant's explicit choice. */
    public const STATUS_FAILED = 'failed';

    /** We chose not to send (see `reason`). */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_SENDING, self::STATUS_SENT,
        self::STATUS_FAILED, self::STATUS_SKIPPED,
    ];

    /** Where the person came from. */
    public const SOURCE_PLAN = 'plan';

    public const SOURCE_CONTRACT = 'contract';

    public const SOURCE_LOYALTY = 'loyalty';

    /**
     * An address the merchant TYPED that matches nobody this app knows. It still
     * receives the campaign — the merchant named it — but it carries no
     * customer_ref, so a `{account_login_url}` in the body resolves to a link
     * that signs nobody in rather than into somebody else's account.
     */
    public const SOURCE_MANUAL = 'manual';

    /** Reason codes; the UI translates each one. */
    public const REASON_UNSUBSCRIBED = 'unsubscribed';

    public const REASON_NO_EMAIL = 'no_email';

    public const REASON_MAIL_ERROR = 'mail_error';

    public const REASON_CAMPAIGN_CANCELLED = 'campaign_cancelled';

    public const REASON_SHOP_NOT_LIVE = 'shop_not_live';

    /** status is guarded: it moves only through the helpers below. */
    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    /** A human label for the list: the name, else the address. */
    public function label(): string
    {
        foreach ([$this->customer_name, $this->email] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '#'.$this->getKey();
    }

    /**
     * Claim this recipient for a send. FALSE when another delivery of the job
     * already did — an atomic pending → sending move.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_SENDING, 'updated_at' => now()]) === 1;
    }

    public function markSent(?string $messageId = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'message_id' => $messageId !== null ? mb_substr($messageId, 0, 255) : null,
            'reason' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'reason' => mb_substr($reason, 0, 255),
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill(['status' => self::STATUS_SKIPPED, 'reason' => $reason])->save();
    }

    /** Put a FAILED attempt back in the queue. */
    public function resetForRetry(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_FAILED)
            ->update(['status' => self::STATUS_PENDING, 'reason' => null, 'updated_at' => now()]) === 1;
    }
}
