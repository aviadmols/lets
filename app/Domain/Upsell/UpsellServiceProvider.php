<?php

namespace App\Domain\Upsell;

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
    }
}
