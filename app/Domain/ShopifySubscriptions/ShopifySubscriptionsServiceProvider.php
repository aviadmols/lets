<?php

namespace App\Domain\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\Console\BackfillContractsCommand;
use App\Domain\ShopifySubscriptions\Console\DispatchDueBillingCyclesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Shopify-Payments subscriptions rail (the pilot app): the due-cycle
 * scheduler command + its cron.
 *
 * SAFE ON THE PUBLIC APP BY CONSTRUCTION: the scanner reads
 * subscription_contracts, and rows only ever exist on a shop whose install
 * carries the subscription scopes (app B). On every PayPlus shop the table is
 * empty, the scan matches nothing, and the rail is inert — no flag needed.
 */
final class ShopifySubscriptionsServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /**
     * Hourly, not every-minute: next_billing_date has date granularity in
     * practice, and an attempt fired within the hour it becomes due is exactly
     * on time for a subscription. Overlap is harmless (three idempotency layers)
     * but pointless — withoutOverlapping skips it.
     */
    private const DISPATCH_DUE_CRON = '5 * * * *';

    /** Overlap-lock lifetime, MINUTES. Never the 24-hour default: a killed
     *  run must cost one skipped tick, not a day of silence. */
    private const DISPATCH_DUE_LOCK_MINUTES = 55;

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchDueBillingCyclesCommand::class,
                // Deliberately NOT scheduled: webhooks keep the mirror fresh, and a
                // recurring full re-read would spend a shop's API budget re-proving
                // what it already knows. This is the catch-up for what predates the
                // webhooks, run when something changed that made contracts visible.
                BackfillContractsCommand::class,
            ]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('shopify-subscriptions:dispatch-due')
                ->cron(self::DISPATCH_DUE_CRON)
                ->withoutOverlapping(self::DISPATCH_DUE_LOCK_MINUTES)
                ->onOneServer();
        });
    }
}
