<?php

namespace App\Domain\Installments\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The receipt of one "find saved cards" pass: who was asked about, what PayPlus
 * answered, and who could not be fixed.
 *
 * This exists because the work cannot happen in a web request. One member costs
 * up to thirteen round trips to PayPlus — check the token we hold, look for a
 * live recurring series, find every customer record under their email, list the
 * cards on each — and a merchant selecting a hundred rows is asking for over a
 * thousand. That request dies at the proxy, and what made it genuinely bad is
 * that the writes ALREADY LANDED: cards were re-pointed and the merchant was
 * shown a broken page instead of the report saying which.
 *
 * So the run is a row, and the report is read off it afterwards. The counters
 * are committed per member together with the cursor, which is what lets a killed
 * worker resume without re-asking PayPlus about everybody — and what makes the
 * numbers on a half-finished run true rather than merely plausible.
 *
 * THE COUNTERS ARE FIVE DIFFERENT NEXT ACTIONS, not one "failed" number:
 *   fixed         a better card was found and saved
 *   already_valid the token we hold works — the ISSUER declined, not the token
 *   ambiguous     several cards were possible and none could be chosen safely
 *   no_last_four  we never had a last-4 to match on (a migrated member)
 *   not_found     PayPlus holds nothing for them
 * Collapsing those into "6 failed" would send a merchant chasing all five.
 */
class TokenRecoveryRun extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'token_recovery_runs';

    /** Find the card and save it. Charges nothing. */
    public const MODE_RECOVER = 'recover';

    /** Find the card, then queue the scheduler's own ChargeJob for it. */
    public const MODE_RECOVER_AND_CHARGE = 'recover_and_charge';

    /** @var list<string> */
    public const MODES = [self::MODE_RECOVER, self::MODE_RECOVER_AND_CHARGE];

    /** Accepted, not yet picked up by a worker. */
    public const STATUS_QUEUED = 'queued';

    /** A worker is walking the selection. Survives restarts via `cursor`. */
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** The merchant stopped it. Everything already committed stays. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * How many names a report may list before it stops naming them.
     *
     * The double-billing list is read by a human who then opens each one, so a
     * list longer than this is not a list, it is a wall.
     */
    public const NAMES_SHOWN = 10;

    /**
     * How long a finished run keeps showing its report on the screen.
     *
     * Long enough that a merchant who started a run and switched tabs still finds
     * the answer waiting; short enough that last week's run is not mistaken for
     * this morning's.
     */
    public const REPORT_VISIBLE_HOURS = 24;

    protected $guarded = [];

    protected $casts = [
        'plan_ids' => 'array',
        'double_billing' => 'array',
        'total' => 'integer',
        'cursor' => 'integer',
        'processed' => 'integer',
        'fixed' => 'integer',
        'already_valid' => 'integer',
        'ambiguous' => 'integer',
        'no_last_four' => 'integer',
        'not_found' => 'integer',
        'skipped' => 'integer',
        'not_probed' => 'integer',
        'charges_queued' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return list<int> */
    public function planIds(): array
    {
        return array_values(array_map('intval', (array) ($this->plan_ids ?? [])));
    }

    public function isFinished(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    public function isRunning(): bool
    {
        return (string) $this->status === self::STATUS_RUNNING
            || (string) $this->status === self::STATUS_QUEUED;
    }

    public function chargesMoney(): bool
    {
        return (string) $this->mode === self::MODE_RECOVER_AND_CHARGE;
    }

    /** 0-100, for the progress bar. A run with nothing in it is already done. */
    public function progressPercent(): int
    {
        $total = max(1, (int) $this->total);

        return (int) min(100, round(((int) $this->processed / $total) * 100));
    }

    /**
     * The percent rounded to the nearest 5, so the bar is a CSS class rather than
     * an inline width (the zero-inline-CSS gate).
     */
    public function progressStep(): int
    {
        return (int) (round($this->progressPercent() / 5) * 5);
    }

    /**
     * Did this run leave anybody un-asked?
     *
     * A cancelled or failed run that stopped at member forty of a hundred is the
     * case the screen must not round off — the other sixty were never asked, and
     * the merchant has to know to run them again.
     */
    public function unreached(): int
    {
        return max(0, (int) $this->total - (int) $this->processed);
    }
}
