<?php

namespace App\Providers;

use App\Services\Shopify\Orders\DefaultShopifyOrderStrategy;
use App\Services\Shopify\Orders\ShopifyOrderStrategy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Shopify boundary's order strategy. Binding it here lets the
        // billing engine's ChargeOrchestrator receive it via constructor
        // injection (as a nullable dependency) and materialize Shopify state
        // after a succeeded ledger row — without the engine depending on a
        // concrete Shopify class. Swap the binding (or null it) to decouple.
        $this->app->bind(ShopifyOrderStrategy::class, DefaultShopifyOrderStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
