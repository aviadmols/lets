<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEV-ONLY tenant binder for local admin viewing.
 *
 * In production the tenant is bound by the embedded-app session-token bridge
 * (shopify-integration) from the verified Shopify session — NEVER from a guess.
 * For local development there is no Shopify session, so this middleware binds the
 * demo shop (the lowest-id Shop, or one named by APP_DEMO_SHOP_DOMAIN) so the
 * BelongsToShop global scope resolves and resources render real seeded data.
 *
 * HARD GUARD: it is a no-op unless app()->isLocal() AND config('app.dev_tenant')
 * is true. It can never run on a production deploy. It is registered only on the
 * admin panel middleware stack, behind those two gates.
 */
class BindDevTenant
{
    // === CONSTANTS ===
    public const CONFIG_FLAG = 'app.dev_tenant';
    public const DOMAIN_ENV = 'APP_DEMO_SHOP_DOMAIN';

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isLocal() || ! config(self::CONFIG_FLAG, false)) {
            return $next($request); // production / disabled → never binds.
        }

        if (Tenant::check()) {
            return $next($request); // a real tenant is already bound → respect it.
        }

        $domain = env(self::DOMAIN_ENV);

        $shop = $domain
            ? Shop::query()->where('shopify_domain', $domain)->first()
            : Shop::query()->orderBy('id')->first();

        if ($shop) {
            Tenant::set($shop);
        }

        return $next($request);
    }
}
