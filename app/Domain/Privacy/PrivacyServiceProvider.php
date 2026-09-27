<?php

namespace App\Domain\Privacy;

use App\Console\Commands\PruneRawPayloads;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Retention: personal data that has done its job does not stay forever.
 *
 *   webhook_events.raw_payload   blanked by privacy:prune-raw-payloads
 *                                (PruneRawPayloads::RETENTION_DAYS after
 *                                processing; the audit row stays)
 *   failed_jobs                  pruned after FAILED_JOB_RETENTION_HOURS — a
 *                                failed privacy job's payload carries the
 *                                customer's email and id
 *
 * The one-off, short-lived tables (login codes/tokens, card-update links,
 * courier-sheet rows) keep their own prunes in their own providers.
 */
final class PrivacyServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /**
     * Failed jobs are kept 30 days: long enough to diagnose and retry a
     * failure found at month end, bounded so payloads do not pile up.
     */
    public const FAILED_JOB_RETENTION_HOURS = 720;

    /** Nightly, off-peak, after the other prunes (03:20–03:40). */
    private const PAYLOAD_PRUNE_CRON = '50 3 * * *';

    private const FAILED_JOBS_PRUNE_CRON = '55 3 * * *';

    /** Overlap-lock lifetime, MINUTES — a killed run costs one skipped night. */
    private const PRUNE_LOCK_MINUTES = 60;

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneRawPayloads::class]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('privacy:prune-raw-payloads')
                ->cron(self::PAYLOAD_PRUNE_CRON)
                ->withoutOverlapping(self::PRUNE_LOCK_MINUTES)
                ->onOneServer();

            $schedule->command('queue:prune-failed', ['--hours' => self::FAILED_JOB_RETENTION_HOURS])
                ->cron(self::FAILED_JOBS_PRUNE_CRON)
                ->withoutOverlapping(self::PRUNE_LOCK_MINUTES)
                ->onOneServer();
        });
    }
}
