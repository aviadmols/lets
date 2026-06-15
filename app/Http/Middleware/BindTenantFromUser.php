<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRODUCTION tenant binding for the Filament admin panel, FROM THE AUTHENTICATED
 * USER. This is the seam Aviad's requirement hinges on: every merchant user is
 * bound to their OWN shop and can NEVER see another store's data.
 *
 * Resolution order on the panel (composes with the embedded session-token seam):
 *   1. If a tenant is ALREADY bound (SessionTokenAuth ran first for an embedded,
 *      Shopify-Admin iframe load), respect it — never override the verified shop.
 *   2. Else, for an authenticated NON-platform user, bind Tenant from
 *      auth()->user()->shop_id.
 *   3. A platform-admin user is intentionally left UNBOUND — they must use the
 *      explicit, audited acrossAllTenants() path, never ambient panel state. With
 *      no tenant bound, the BelongsToShop global scope returns ZERO rows, so the
 *      panel cannot leak any single shop's data to the owner by accident.
 *
 * FAIL CLOSED: a merchant user whose shop_id is null, or whose shop row is
 * missing / not live, is DENIED (403) and no tenant is bound. We never guess a
 * shop, never fall through to "the last shop", never default to the first row.
 *
 * Ordering on the panel stack: this runs AFTER Authenticate (we need a user) and
 * — when present — after SessionTokenAuth (embedded). BindDevTenant stays strictly
 * dev-only and is a no-op the moment a real tenant is bound here.
 */
final class BindTenantFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. A verified embedded session already bound the shop → respect it.
        if (Tenant::check()) {
            return $next($request);
        }

        $user = $request->user();

        // No authenticated user → let the panel's Authenticate middleware handle
        // the redirect to login. We only bind for authenticated requests.
        if ($user === null) {
            return $next($request);
        }

        // 3. Platform owner: deliberately UNBOUND. They reach cross-tenant data
        //    only through the audited acrossAllTenants() seam, never here.
        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        // 2. Merchant user: must be bound to exactly one live shop. Fail closed.
        $shop = $user->shop_id !== null
            ? Shop::query()->whereKey($user->shop_id)->first()
            : null;

        if ($shop === null || ! $shop->isLive()) {
            // Shopless merchant (never onboarded, or shop hard-deleted by
            // shop/redact), OR a shop that is no longer live (uninstalled). Deny —
            // never silently expose all-tenant or no-tenant state as if it were
            // theirs, and never bind an uninstalled shop (mirrors SessionTokenAuth).
            abort(Response::HTTP_FORBIDDEN, __('Your account is not linked to an active store.'));
        }

        Tenant::set($shop);

        try {
            return $next($request);
        } finally {
            // Web requests can share a process (Octane/FrankPHP); clear so the next
            // request never inherits this shop. Jobs use TenantContext separately.
            Tenant::clear();
        }
    }
}
