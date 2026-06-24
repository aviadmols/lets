<?php

use App\Http\Middleware\AddHstsHeader;
use App\Http\Middleware\AllowExtensionCors;
use App\Http\Middleware\EmbeddedAuthenticate;
use App\Http\Middleware\SessionTokenAuth;
use App\Http\Middleware\VerifyShopifyAppProxy;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

            // CUSTOMER PORTAL magic-link routes (Phase 6.5). SIGNED links are the
            // auth (no admin session); the controller binds the tenant from the
            // signed shop and scopes every query to the signed customer. In the
            // `web` group so the rendered page gets a session + CSRF token for its
            // pause/resume/cancel POST forms (the signature stays the primary auth).
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/portal.php'));

            // App-Proxy seam for the checkout/post-purchase EXTENSIONS. Stateless
            // JSON — no session, no CSRF token (server-to-server via the Shopify
            // proxy). VerifyShopifyAppProxy is the auth (Shopify `signature`) and
            // binds the tenant from the verified shop. The route file applies the
            // proxy middleware itself, so no web/api group is needed.
            \Illuminate\Support\Facades\Route::group([], base_path('routes/proxy.php'));

            // WooCommerce plugin → SaaS connect handshake. Stateless JSON, HMAC-auth
            // (VerifyWooCommerceSignature) — no web group / no CSRF, like proxy.php.
            \Illuminate\Support\Facades\Route::group([], base_path('routes/woocommerce.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind Railway's edge proxy (TLS terminates at the edge, the container
        // gets HTTP + X-Forwarded-Proto: https). Trust the forwarded headers so
        // Laravel/Filament generate https:// URLs (assets, redirects, OAuth
        // callbacks) — otherwise asset URLs come out http:// on an https page and
        // the browser blocks them (mixed content → broken admin). Railway is the
        // only hop, so trust all proxies.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // Pin https in the browser (HSTS) so it never falls back to http and the
        // stale uPress vhost. Global so it covers admin + storefront + proxy. Only
        // emits over a real https request in production (see the middleware).
        $middleware->append(AddHstsHeader::class);

        // Named middleware aliases for the Shopify boundary.
        $middleware->alias([
            'shopify.webhook' => VerifyShopifyWebhook::class,
            'shopify.session' => SessionTokenAuth::class,
            // Embedded-admin auth + managed install for the Filament panel (verify
            // session token → token exchange/install → Auth::login → bind tenant).
            'shopify.embedded' => EmbeddedAuthenticate::class,
            'shopify.proxy' => VerifyShopifyAppProxy::class,
            // CORS for the checkout/customer-account UI extension cross-origin
            // fetch — attached ONLY to the upsell offer + accept-api routes.
            'extension.cors' => AllowExtensionCors::class,
        ]);

        // Shopify webhooks are server-to-server POSTs with no CSRF token — exempt
        // the webhook endpoint (HMAC is the auth, verified by VerifyShopifyWebhook).
        // The extensions' signed JSON accept (upsell.accept.api) is likewise auth'd
        // by the URL signature, not a session/CSRF token, so POSTs to it are exempt.
        $middleware->validateCsrfTokens(except: [
            'shopify/webhooks',
            'shopify/webhooks/*',
            'upsell/accept-api',
            // PayPlus → SaaS deposit + gateway callbacks (server-to-server; auth is the
            // opaque wc_shop_token segment + idempotent activation/mark-paid, not CSRF).
            'woocommerce/deposit/callback/*',
            'woocommerce/gateway/callback/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
