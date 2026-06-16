<?php

namespace App\Providers;

use App\Services\Shopify\Orders\DefaultShopifyOrderStrategy;
use App\Services\Shopify\Orders\ShopifyOrderStrategy;
use Illuminate\Support\Facades\URL;
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
        // Railway terminates TLS at its edge and forwards plain HTTP to the
        // container. Force HTTPS for all generated URLs in production so that
        // asset(), route(), and url() helpers never emit http:// links —
        // preventing mixed-content errors in the browser.
        // The Caddyfile + trustProxies() handle the X-Forwarded-Proto path;
        // this is a belt-and-suspenders guarantee for any edge case where the
        // scheme is not detected from the request.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
