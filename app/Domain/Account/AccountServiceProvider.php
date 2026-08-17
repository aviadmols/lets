<?php

namespace App\Domain\Account;

use App\Models\CustomerLoginCode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the personal area's one scheduled chore: retiring spent sign-in codes.
 *
 * `Prunable` on the model only describes WHICH rows may go; nothing deletes them
 * until something runs `model:prune`. Without this the table the migration calls
 * short-lived grows forever, one row per sign-in attempt across every shop.
 *
 * The model list is explicit rather than letting Laravel scan for Prunable models:
 * a scan that discovers a tenant-scoped model nobody meant to prune is exactly the
 * kind of surprise a multi-tenant database should not have.
 */
final class AccountServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /**
     * Nightly, off-peak. Codes live ten minutes and are kept a further day so an
     * abuse report can still be answered, so there is nothing to gain from
     * pruning more often — and a delete sweep is not what the queue should be
     * doing while shoppers are checking out.
     */
    private const PRUNE_CRON = '20 3 * * *';

    public function boot(): void
    {
        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('model:prune', ['--model' => [CustomerLoginCode::class]])
                ->cron(self::PRUNE_CRON)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
