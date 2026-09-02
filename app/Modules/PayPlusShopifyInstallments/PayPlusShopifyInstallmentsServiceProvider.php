<?php

namespace App\Modules\PayPlusShopifyInstallments;

use App\Domain\Billing\Contracts\DocumentPolicy;
use App\Domain\Billing\DefaultDocumentPolicy;
use App\Modules\PayPlusShopifyInstallments\Console\Commands\DispatchDuePlansCommand;
use App\Modules\PayPlusShopifyInstallments\Console\Commands\DispatchRemindersCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The shared billing engine's service provider.
 *
 * DELIBERATELY ABSENT: the reference engine's global container bind of
 * PayPlusInstallmentGatewayInterface → PayPlusInstallmentGateway
 * (the source's line 34). That bind let a job read the WRONG shop's credentials
 * because the gateway pulled them from config(). Here the gateway is built ONLY
 * via PayPlusGatewayFactory::for($shop) — per-shop, never from the container.
 *
 * What we DO bind: the DocumentPolicy contract (so the orchestrator never names
 * a document type), the scheduler command, and the cron schedule.
 */
final class PayPlusShopifyInstallmentsServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /** How often the due-plan scheduler fans out. */
    private const DISPATCH_DUE_CRON = '*/5 * * * *'; // every 5 minutes

    /** How often the upcoming-charge reminder scan runs (hourly is ample). */
    private const DISPATCH_REMINDERS_CRON = '0 * * * *'; // top of every hour

    /**
     * Overlap-lock lifetimes, in MINUTES, and never left to the default.
     *
     * withoutOverlapping() takes the lock before the run and releases it after —
     * but a process that is KILLED rather than finished (a Railway redeploy, an
     * OOM, a spot reclaim) never reaches the release, and the lock then stands
     * until it expires. Laravel's default expiry is 24 HOURS, which on this
     * command means every shop's charges stop for a day, silently, because a
     * container restarted at the wrong moment.
     *
     * So each one is set to comfortably longer than the run it guards and far
     * shorter than the damage: a killed run costs one skipped tick, not a day.
     */
    private const DISPATCH_DUE_LOCK_MINUTES = 10;

    private const DISPATCH_REMINDERS_LOCK_MINUTES = 55;

    public function register(): void
    {
        // The orchestrator depends on the contract; resolve the default policy.
        $this->app->bind(DocumentPolicy::class, DefaultDocumentPolicy::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchDuePlansCommand::class,
                DispatchRemindersCommand::class,
            ]);
        }

        // The mail engine's views live under the `emails.*` namespace (the default
        // resources/views path), so no addNamespace() is needed; the per-template
        // Blade files resolve directly. Merchant-edited bodies never touch Blade.

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('payplus:dispatch-due')
                ->cron(self::DISPATCH_DUE_CRON)
                ->withoutOverlapping(self::DISPATCH_DUE_LOCK_MINUTES)
                ->onOneServer();

            // Upcoming-charge reminders. withoutOverlapping + the per-cycle meta
            // guard make a re-run a no-op, so an overlapping tick never double-sends.
            $schedule->command('payplus:dispatch-reminders')
                ->cron(self::DISPATCH_REMINDERS_CRON)
                ->withoutOverlapping(self::DISPATCH_REMINDERS_LOCK_MINUTES)
                ->onOneServer();
        });
    }
}
