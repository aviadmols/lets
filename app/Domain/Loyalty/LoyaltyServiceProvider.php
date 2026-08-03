<?php

namespace App\Domain\Loyalty;

use App\Console\Commands\GrantLoyaltyBirthdayPoints;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the loyalty club's one scheduled job: the birthday gift.
 *
 * Inert by construction on a shop with no club — the command reads each shop's
 * settings and skips any where the program is off or the birthday bonus is
 * zero, so no flag is needed to keep it quiet.
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /**
     * Early morning, once a day. A birthday is a date, not a moment: granting it
     * at 06:00 local time means the points are there before the member is likely
     * to look, and the year-stamped idempotency key makes a re-run free.
     */
    private const BIRTHDAY_CRON = '0 6 * * *';

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GrantLoyaltyBirthdayPoints::class]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('loyalty:grant-birthday-points')
                ->cron(self::BIRTHDAY_CRON)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
