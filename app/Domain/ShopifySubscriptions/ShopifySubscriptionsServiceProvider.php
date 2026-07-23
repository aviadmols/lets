<?php

namespace App\Domain\ShopifySubscriptions;

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

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchDueBillingCyclesCommand::class,
            ]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('shopify-subscriptions:dispatch-due')
                ->cron(self::DISPATCH_DUE_CRON)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
