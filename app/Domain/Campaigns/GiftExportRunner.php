<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Jobs\RunGiftExportJob;
use App\Domain\Campaigns\Models\GiftExportRow;
use App\Domain\Campaigns\Models\GiftExportRun;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the gift list export off the web request.
 *
 * Every line costs a live store read (up to three for a member with no
 * profile), so a real list is minutes of work. Inside the click it came back cut
 * at 500 rows or 20 seconds — or not at all, when the proxy killed the request.
 *
 * THE SHAPE:
 *   1. start()   — the click: writes the run with the rule as it stood on screen,
 *                  queues the worker, returns at once.
 *   2. advance() — the worker, in bounded slices. The first slice freezes WHO is
 *                  on the list (one row per recipient, no address yet); every
 *                  slice after resolves addresses a row at a time.
 *
 * A ROW AT A TIME, COMMITTED. The store read happens with no transaction held;
 * the row's line and the run's counter are then written together, and only for
 * a row that has no line yet. So a killed worker redoes at most one read, and a
 * retry can never count a row twice.
 */
final class GiftExportRunner
{
    // === CONSTANTS ===
    /** Rows one job invocation resolves before handing itself back to the queue. */
    public const ROWS_PER_JOB = 50;

    /**
     * And a clock on the same slice. A store that answers slowly must cost one
     * extra queue hop, not a job killed at its timeout mid-row.
     */
    public const SECONDS_PER_JOB = 45;

    /** Rows inserted per statement when the recipient list is frozen. */
    private const SEED_CHUNK = 500;

    /**
     * A run whose worker has not moved it in this long is dead — the container
     * was replaced, the queue lost it. Said on the row, so the screen stops
     * waiting and the merchant can start again.
     */
    public const STALE_MINUTES = 15;

    public function __construct(
        private readonly GiftEligibility $eligibility,
        private readonly GiftListExporter $exporter,
    ) {}

    /**
     * Accept an export and hand it to the queue.
     *
     * @param  array<int, int>  $productIds
     * @param  array<int, string>  $emails
     */
    public function start(Shop $shop, int $minCycles, array $productIds, array $emails): GiftExportRun
    {
        // The shop's previous file goes now: its lines are customers' addresses,
        // which this app keeps only for as long as a download needs them.
        GiftExportRow::query()->delete();

        $run = new GiftExportRun;
        $run->forceFill([
            'shop_id' => $shop->getKey(),
            'min_cycles' => max(GiftEligibility::MIN_THRESHOLD, $minCycles),
            'product_ids' => $productIds !== [] ? array_values($productIds) : null,
            'emails' => $emails !== [] ? array_values($emails) : null,
            'status' => GiftExportRun::STATUS_QUEUED,
            'requested_by' => Auth::id(),
        ])->save();

        Log::info('gift_export.started', ['run_id' => $run->getKey(), 'shop_id' => $shop->getKey()]);

        RunGiftExportJob::dispatch((int) $shop->getKey(), (int) $run->getKey());

        return $run->refresh();
    }

    /**
     * The shop's most recent export still worth showing, with a dead one marked
     * as such first.
     */
    public function latest(): ?GiftExportRun
    {
        $run = GiftExportRun::query()
            ->where('created_at', '>=', now()->subHours(GiftExportRun::VISIBLE_HOURS))
            ->latest('id')
            ->first();

        if ($run !== null && ! $run->isFinished()
            && $run->updated_at !== null
            && $run->updated_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
            $this->finish((int) $run->getKey(), GiftExportRun::STATUS_FAILED, 'stalled');
            $run->refresh();
        }

        return $run;
    }

    /**
     * Do a bounded slice of the run.
     *
     * @return bool true when nothing is left to do (finished or failed); false
     *              when the job should hand itself back to the queue
     */
    public function advance(
        Shop $shop,
        int $runId,
        int $maxRows = self::ROWS_PER_JOB,
        int $seconds = self::SECONDS_PER_JOB,
    ): bool {
        // Tenant-scoped: another shop's run id resolves to nothing.
        $run = GiftExportRun::query()->find($runId);

        if ($run === null || $run->isFinished()) {
            return true;
        }

        try {
            if ((string) $run->status === GiftExportRun::STATUS_QUEUED) {
                $this->seed($run);
            }

            $deadline = microtime(true) + $seconds;

            for ($i = 0; $i < $maxRows; $i++) {
                if (microtime(true) > $deadline) {
                    return false;
                }

                $row = GiftExportRow::query()
                    ->where('run_id', $runId)
                    ->whereNull('fields')
                    ->orderBy('position')
                    ->first();

                if ($row === null) {
                    $this->finish($runId, GiftExportRun::STATUS_COMPLETED);

                    return true;
                }

                $this->commit($runId, (int) $row->getKey(), $this->exporter->fields($shop, (array) $row->recipient));
            }

            // The slice ran out exactly on the last row: say so now rather than
            // spending a queue hop to discover it.
            if (! GiftExportRow::query()->where('run_id', $runId)->whereNull('fields')->exists()) {
                $this->finish($runId, GiftExportRun::STATUS_COMPLETED);

                return true;
            }
        } catch (Throwable $e) {
            $this->markFailed($runId, $e);

            return true;
        }

        return false;
    }

    /**
     * Freeze WHO is on the list: one row per recipient, in the preview's order,
     * with no address yet. All or nothing, so a retry starts clean.
     */
    private function seed(GiftExportRun $run): void
    {
        $recipients = $this->eligibility->qualifying(
            (int) $run->min_cycles,
            null,
            $run->productIds(),
            $run->emailList(),
        );

        DB::transaction(function () use ($run, $recipients): void {
            GiftExportRow::query()->where('run_id', $run->getKey())->delete();

            $now = now();
            $position = 0;

            foreach ($recipients->chunk(self::SEED_CHUNK) as $chunk) {
                $insert = [];
                foreach ($chunk as $recipient) {
                    $insert[] = [
                        'shop_id' => (int) $run->shop_id,
                        'run_id' => (int) $run->getKey(),
                        'position' => $position++,
                        'recipient' => json_encode($recipient, JSON_UNESCAPED_UNICODE),
                        'fields' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                GiftExportRow::query()->insert($insert);
            }

            $run->forceFill([
                'status' => GiftExportRun::STATUS_RUNNING,
                'total' => $position,
                'processed' => 0,
                'started_at' => $now,
            ])->save();
        });
    }

    /**
     * One row's line and the run's counter, together — and only if the row had
     * no line yet, which is what keeps a retried job from counting it twice.
     *
     * @param  list<string>  $fields
     */
    private function commit(int $runId, int $rowId, array $fields): void
    {
        DB::transaction(function () use ($runId, $rowId, $fields): void {
            $written = GiftExportRow::query()
                ->whereKey($rowId)
                ->whereNull('fields')
                // The `encrypted:array` cast's own format, written through the
                // query builder so the null guard and the write are one statement.
                ->update(['fields' => Crypt::encryptString(json_encode($fields, JSON_UNESCAPED_UNICODE)), 'updated_at' => now()]);

            if ($written === 1) {
                GiftExportRun::query()->whereKey($runId)->increment('processed');
            }
        });
    }

    private function finish(int $runId, string $status, ?string $error = null): void
    {
        GiftExportRun::query()
            ->whereKey($runId)
            ->whereNotIn('status', GiftExportRun::TERMINAL_STATUSES)
            ->update([
                'status' => $status,
                'error' => $error,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Said ON THE ROW, so the screen stops polling a worker that is not coming. */
    public function markFailed(int $runId, Throwable $e): void
    {
        Log::error('gift_export.failed', [
            'run_id' => $runId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $this->finish($runId, GiftExportRun::STATUS_FAILED, mb_substr($e->getMessage(), 0, 2000));
    }
}
