<?php

namespace App\Domain\Campaigns\Studio\Models;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat turn — and, for an assistant row, THE proposal.
 *
 * Status moves only through the guarded helpers below, each an atomic
 * conditional UPDATE (the claimForSending idiom): a redelivered job finds the
 * row already claimed and stops, an approval finds it already applied and
 * stops, and no path can resurrect a discarded proposal.
 *
 *   pending ──claimRunning()──▶ running ──propose()──▶ proposed ──▶ applied
 *      │                          │                       ├──────▶ discarded
 *      └────────fail()────────────┴──▶ failed             └──────▶ stale
 */
class AiChatMessage extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'ai_chat_messages';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /** User rows are born in this state and never move. */
    public const STATUS_SENT = 'sent';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DISCARDED = 'discarded';

    /** The document moved since the ops were computed — re-ask, never merge. */
    public const STATUS_STALE = 'stale';

    public const STATUS_FAILED = 'failed';

    /** An assistant row in one of these still owes the screen an answer. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'ops' => 'array',
            'base_version' => 'integer',
            'applied_version' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    /** Same raw read as every status() here — the column/method shadowing trap. */
    public function status(): string
    {
        return (string) ($this->attributes['status'] ?? self::STATUS_PENDING);
    }

    // === Guarded transitions ===

    /** The job's claim. FALSE = another delivery already runs this row. */
    public function claimRunning(): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_RUNNING, 'updated_at' => now()]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    /**
     * The validated proposal, onto the row the screen is polling.
     *
     * @param  list<array<string, mixed>>  $ops
     */
    public function propose(string $explanation, array $ops, int $baseVersion): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_RUNNING)
            ->update([
                'status' => self::STATUS_PROPOSED,
                'content' => $explanation,
                'ops' => json_encode($ops, JSON_UNESCAPED_UNICODE),
                'base_version' => $baseVersion,
                'updated_at' => now(),
            ]);

        $this->refresh();
    }

    public function fail(string $reason, ?string $explanation = null): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', self::OPEN_STATUSES)
            ->update([
                'status' => self::STATUS_FAILED,
                'failure_reason' => mb_substr($reason, 0, 64),
                'content' => $explanation,
                'updated_at' => now(),
            ]);

        $this->refresh();
    }

    /** The approval landed; the version it created is the audit link. */
    public function markApplied(int $version): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PROPOSED)
            ->update(['status' => self::STATUS_APPLIED, 'applied_version' => $version, 'updated_at' => now()]) === 1;

        if ($moved) {
            $this->refresh();
        }

        return $moved;
    }

    public function markDiscarded(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PROPOSED)
            ->update(['status' => self::STATUS_DISCARDED, 'updated_at' => now()]);

        $this->refresh();
    }

    /** The document moved under the proposal. The answer is re-ask, not merge. */
    public function markStale(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PROPOSED)
            ->update(['status' => self::STATUS_STALE, 'updated_at' => now()]);

        $this->refresh();
    }
}
