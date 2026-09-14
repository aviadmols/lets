<?php

namespace App\Domain\Bulk\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The receipt of one bulk edit: what was asked for, on which subscriptions, by
 * whom, and what became of it.
 *
 * It is a row rather than a cache entry (the import screen's choice) because the
 * two runs are answering different questions. An import's progress is interesting
 * for the ninety seconds it takes; a bulk edit's is interesting in three weeks,
 * when a customer asks why their charge date moved and the merchant needs to find
 * the click that moved it — alongside the four thousand other subscriptions it
 * moved at the same moment.
 *
 * The counters are the honest part. `matched_count` is what the merchant confirmed;
 * `processed_count` is what the worker actually reached; `changed` / `skipped` /
 * `failed` split that into work done, rows left alone (already correct, or no
 * longer eligible), and rows that errored. A run can legitimately change fewer
 * rows than were matched, and hiding that gap behind one number would be the
 * easiest lie this screen could tell.
 *
 * Tenant-scoped like everything else it touches.
 */
class BulkSubscriptionEdit extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'bulk_subscription_edits';

    /** Accepted, not yet picked up by a worker. */
    public const STATUS_QUEUED = 'queued';

    /** A worker is walking the matched set. Survives worker restarts via cursor_id. */
    public const STATUS_RUNNING = 'running';

    /** Every matched row was reached. (Some may have been skipped or failed.) */
    public const STATUS_COMPLETED = 'completed';

    /** The run stopped on an error. cursor_id says how far it got. */
    public const STATUS_FAILED = 'failed';

    /** A merchant stopped it mid-flight. Committed chunks stand. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Runs that are over, whichever way. The screen stops polling on these. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** How many recent runs the screen lists. A receipt book, not an archive. */
    public const HISTORY_LIMIT = 10;

    /**
     * Guarded so a screen's array cannot set the tenant or re-write the outcome.
     * The runner writes counters and status with explicit forceFill/increment.
     */
    protected $guarded = ['shop_id', 'status', 'processed_count', 'changed_count', 'skipped_count', 'failed_count', 'cursor_id'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'criteria' => 'array',
            'matched_count' => 'integer',
            'eligible_count' => 'integer',
            'processed_count' => 'integer',
            'changed_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'cursor_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isFinished(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    public function isRunning(): bool
    {
        return (string) $this->status === self::STATUS_RUNNING;
    }

    /**
     * How far along, against the count the merchant confirmed.
     *
     * Capped at 100 because the two numbers are measured at different moments: a
     * run that skips nothing can still process more rows than were matched if
     * subscriptions were created into the filter while it walked.
     */
    public function progressPercent(): int
    {
        $target = max(1, (int) $this->eligible_count);

        return (int) min(100, round(((int) $this->processed_count / $target) * 100));
    }

    /**
     * The percent rounded to the nearest 5, so the bar can be a CSS class instead
     * of an inline width (the zero-inline-CSS gate).
     */
    public function progressStep(): int
    {
        return (int) (round($this->progressPercent() / 5) * 5);
    }
}
