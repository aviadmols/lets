<?php

namespace App\Domain\Import;

/**
 * What the dry run found — and, after a commit, what it did.
 *
 * The counts are exact for a file of any size; the LISTS are bounded (see
 * MAX_REPORTED_ISSUES). That split is deliberate: a merchant with a broken export
 * needs to know that 4,812 rows failed and what the first two hundred failures
 * look like. Printing all 4,812 is not more honest, it is just unreadable — and it
 * is also how a report of a 50k-row file runs the importer out of memory, which
 * would be a validation pass that crashes on exactly the files it exists for.
 *
 * `money` is the number a merchant actually decides on: what this file, if
 * committed with charging switched on, would bill in the next month.
 */
final class ImportReport
{
    // === CONSTANTS ===
    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_COMMIT = 'commit';

    /** The window the "money about to move" figure covers. */
    public const HORIZON_DAYS = 30;

    public int $rows = 0;

    public int $valid = 0;

    public int $invalid = 0;

    public int $creates = 0;

    public int $updates = 0;

    public int $skipped = 0;

    /** Rows a commit actually wrote (0 on a dry run). */
    public int $written = 0;

    /** Plans that will/did get a next_charge_at, and their total per horizon. */
    public int $scheduled = 0;

    public float $moneyInHorizon = 0.0;

    /** Payment methods (vault rows) the run will create or refresh. */
    public int $tokens = 0;

    /** Consent rows transcribed from the source system. */
    public int $consents = 0;

    /** @var list<array{line: int, key: string, message: string}> */
    public array $errors = [];

    /** @var list<array{line: int, key: string, message: string}> */
    public array $warnings = [];

    /** @var list<string> headers in the file we do not understand */
    public array $unknownHeaders = [];

    /** @var array<string, int> error message => how many rows hit it */
    public array $errorTally = [];

    public bool $aborted = false;

    public ?string $abortReason = null;

    public function __construct(public readonly string $mode = self::MODE_DRY_RUN) {}

    public function addError(int $line, string $key, string $message): void
    {
        $this->errorTally[$message] = ($this->errorTally[$message] ?? 0) + 1;

        if (count($this->errors) < SubscriptionCsvSchema::MAX_REPORTED_ISSUES) {
            $this->errors[] = ['line' => $line, 'key' => $key, 'message' => $message];
        }
    }

    public function addWarning(int $line, string $key, string $message): void
    {
        if (count($this->warnings) < SubscriptionCsvSchema::MAX_REPORTED_ISSUES) {
            $this->warnings[] = ['line' => $line, 'key' => $key, 'message' => $message];
        }
    }

    public function abort(string $reason): void
    {
        $this->aborted = true;
        $this->abortReason = $reason;
    }

    /** A file is importable when it parsed AND every single row is clean. */
    public function isClean(): bool
    {
        return ! $this->aborted && $this->rows > 0 && $this->invalid === 0;
    }

    /** How many reported issues were dropped from the printed list. */
    public function hiddenErrors(): int
    {
        return max(0, array_sum($this->errorTally) - count($this->errors));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'rows' => $this->rows,
            'valid' => $this->valid,
            'invalid' => $this->invalid,
            'creates' => $this->creates,
            'updates' => $this->updates,
            'skipped' => $this->skipped,
            'written' => $this->written,
            'scheduled' => $this->scheduled,
            'money_in_horizon' => round($this->moneyInHorizon, 2),
            'tokens' => $this->tokens,
            'consents' => $this->consents,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'error_tally' => $this->errorTally,
            'unknown_headers' => $this->unknownHeaders,
            'aborted' => $this->aborted,
            'abort_reason' => $this->abortReason,
        ];
    }
}
