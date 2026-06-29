<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Who may open /horizon (the background-jobs dashboard: queues, throughput, failed
     * jobs) in production. Gated to PLATFORM ADMINS — the app owner who oversees every
     * shop's jobs. A merchant (or an anonymous request) is denied. We key on the role
     * flag, not an email allow-list, so a new owner never gets silently locked out.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            return $user !== null
                && method_exists($user, 'isPlatformAdmin')
                && $user->isPlatformAdmin();
        });
    }
}
