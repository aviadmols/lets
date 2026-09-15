<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftExportRow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the campaigns module (loyalty GIFT orders).
 *
 * Merchant-driven from the admin screen: a gift goes out because someone decided
 * to send it, not because a clock fired. The one scheduled task is housekeeping —
 * the export lines hold customers' addresses, which this app keeps only for as
 * long as a download needs them.
 */
final class CampaignsServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /** Hourly, off the top of the hour. */
    private const EXPORT_PRUNE_CRON = '17 * * * *';

    /** Minutes — never the 24-hour default a killed run would sit on. */
    private const EXPORT_PRUNE_LOCK_MINUTES = 30;

    public function register(): void
    {
        // Stateless collaborators; singletons so the resolver's per-request client
        // reuse is not thrown away between recipients in one generation run.
        $this->app->singleton(GiftEligibility::class);
        $this->app->singleton(GiftAddressResolver::class);
        $this->app->singleton(GiftCampaignGenerator::class);
        $this->app->singleton(GiftOrderReconciler::class);
        $this->app->singleton(GiftListExporter::class);
        $this->app->singleton(GiftExportRunner::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\ReconcileGiftOrdersCommand::class]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('model:prune', ['--model' => [GiftExportRow::class]])
                ->cron(self::EXPORT_PRUNE_CRON)
                ->withoutOverlapping(self::EXPORT_PRUNE_LOCK_MINUTES)
                ->onOneServer();
        });
    }
}
