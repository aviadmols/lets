<?php

use App\Http\Middleware\SessionTokenAuth;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Shopify boundary routes: OAuth + the platform webhook endpoint.
            // Kept in the `web` group so OAuth can use the session for nothing
            // (state is cached, not sessioned) but inherits standard middleware.
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/shopify.php'));

            // Post-purchase / thank-you-page UPSELL storefront routes (Phase 6).
            // SIGNED links are the auth; each controller binds the tenant from the
            // signed shop id (no admin session on the storefront).
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/upsell.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Named middleware aliases for the Shopify boundary.
        $middleware->alias([
            'shopify.webhook' => VerifyShopifyWebhook::class,
            'shopify.session' => SessionTokenAuth::class,
        ]);

        // Shopify webhooks are server-to-server POSTs with no CSRF token — exempt
        // the webhook endpoint (HMAC is the auth, verified by VerifyShopifyWebhook).
        $middleware->validateCsrfTokens(except: [
            'shopify/webhooks',
            'shopify/webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
