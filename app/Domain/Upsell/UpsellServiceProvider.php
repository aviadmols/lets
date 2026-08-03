<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Holds\ReleaseDueHoldsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the post-purchase upsell pillar (Phase 6): the `upsell::` storefront view
 * namespace, the charge service's dependencies, and the signed-URL helper. The
 * actual gateway is built per-shop via PayPlusGatewayFactory::for($shop) inside
 * the service — never bound globally (no cross-tenant token leak).
 */
final class UpsellServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    public const VIEW_NAMESPACE = 'upsell';

    /**
     * How often the add-on windows are swept.
     *
     * Every minute, because the window itself is measured in minutes: a shopper
     * told "you have 20 minutes to add something" should not wait 25 for their
     * order to move, and a merchant should not have to explain the difference.
     */
    private const RELEASE_CRON = '* * * * *';

    public function register(): void
    {
        $this->app->singleton(UpsellResolver::class);
        $this->app->singleton(UpsellSignedUrlService::class);

        // In production the charge service builds the draft-order service PER SHOP
        // from that shop's Admin client at call time (no cross-tenant leak), so no
        // factory is injected here. Tests inject a factory returning a recording
        // fake to keep HTTP out of the suite.
        $this->app->bind(UpsellChargeService::class, fn ($app): UpsellChargeService => new UpsellChargeService(
            resolver: $app->make(UpsellResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../../resources/views/upsell', self::VIEW_NAMESPACE);

        if ($this->app->runningInConsole()) {
            $this->commands([ReleaseDueHoldsCommand::class]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            // withoutOverlapping is belt on top of an idempotent release: a slow
            // pass must not have a second one racing it across the same rows.
            $schedule->command('upsell:release-holds')
                ->cron(self::RELEASE_CRON)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
