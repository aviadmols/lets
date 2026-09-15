<?php

namespace App\Domain\Campaigns\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "export the gift list with addresses" request.
 *
 * The export reads each recipient's address from the store, one read per person,
 * so a real list is minutes of work. It runs on a worker; this row is what the
 * screen polls, and what the finished file is built from.
 */
class GiftExportRun extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'gift_export_runs';

    /** Accepted, not yet picked up by a worker. */
    public const STATUS_QUEUED = 'queued';

    /** A worker is reading addresses. */
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED];

    /**
     * How long a finished export stays downloadable from the screen. The same
     * window the row prune uses — after it, the lines are gone.
     */
    public const VISIBLE_HOURS = 24;

    protected $guarded = [];

    protected $casts = [
        'product_ids' => 'array',
        'emails' => 'array',
        'min_cycles' => 'integer',
        'total' => 'integer',
        'processed' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(GiftExportRow::class, 'run_id');
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_values(array_map('intval', (array) ($this->product_ids ?? [])));
    }

    /** @return list<string> */
    public function emailList(): array
    {
        return array_values(array_map('strval', (array) ($this->emails ?? [])));
    }

    public function isFinished(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return (string) $this->status === self::STATUS_COMPLETED;
    }

    /** 0-100. A run with nobody in it is done the moment it is counted. */
    public function progressPercent(): int
    {
        if ((int) $this->total === 0) {
            return $this->isFinished() ? 100 : 0;
        }

        return (int) min(100, round(((int) $this->processed / (int) $this->total) * 100));
    }

    /** Rounded to 5 so the bar is a CSS class, not an inline width. */
    public function progressStep(): int
    {
        return (int) (round($this->progressPercent() / 5) * 5);
    }
}
