<?php

namespace App\Domain\Bulk;

/**
 * What one chunk of a bulk edit actually did.
 *
 * Three numbers, kept apart on purpose. A merchant who asked to move 12,000
 * charge dates and reads "12,000 processed" learns nothing; the useful answer is
 * that 11,840 moved, 160 were skipped because they had been cancelled since the
 * count was taken, and none failed. CHANGED is the only number that means work
 * happened — a row already sitting on the requested value is skipped, which is
 * what makes re-running a bulk edit harmless.
 *
 * No constants: this object is three counters and their sum, and the column names
 * they land in belong to the run row, not here.
 */
final class BulkOutcome
{
    public function __construct(
        public readonly int $changed = 0,
        public readonly int $skipped = 0,
        public readonly int $failed = 0,
    ) {}

    /** Every row this outcome accounts for — what the run's cursor just passed. */
    public function total(): int
    {
        return $this->changed + $this->skipped + $this->failed;
    }
}
