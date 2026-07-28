<?php

namespace App\Domain\Campaigns;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the campaigns module (loyalty GIFT orders).
 *
 * Deliberately thin: the feature is merchant-driven from the admin screen, so
 * there is no scheduler and no cron here — a gift goes out because someone decided
 * to send it, not because a clock fired.
 */
final class CampaignsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless collaborators; singletons so the resolver's per-request client
        // reuse is not thrown away between recipients in one generation run.
        $this->app->singleton(GiftEligibility::class);
        $this->app->singleton(GiftAddressResolver::class);
        $this->app->singleton(GiftCampaignGenerator::class);
    }
}
