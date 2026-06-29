<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\PlatformContext;
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
 *   3. A platform-admin user is, by default, left UNBOUND — they must use the
 *      explicit, audited acrossAllTenants() path, never ambient panel state. With
 *      no tenant bound, the BelongsToShop global scope returns ZERO rows, so the
 *      panel cannot leak any single shop's data to the owner by accident. The ONE
 *      deliberate exception is "Enter shop" (W2): when the platform admin has
 *      explicitly entered a shop (PlatformContext::enteredShopId), we bind exactly
 *      that shop for the request — the SAME Tenant::set + global scope a merchant
 *      uses, so entering shop A scopes them to A only and never leaks B.
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

        // 3. Platform owner. Default = UNBOUND (platform mode → the Shops list,
        //    per-shop screens hidden). If they have explicitly ENTERED a shop, bind
        //    exactly that one for the request. We allow entering ANY existing shop —
        //    including an uninstalled one — so the owner can inspect/remediate a shop
        //    post-uninstall (read its ledger, resume a paused plan, see why it
        //    churned). The merchant path below stays fail-closed on isLive(); the
        //    platform admin deliberately does not, because oversight needs the
        //    uninstalled rows. Either way it is ONE shop, scoped by the same global
        //    scope — entering A never exposes B.
        if ($user->isPlatformAdmin()) {
            $enteredShopId = PlatformContext::enteredShopId();

            if ($enteredShopId === null) {
                return $next($request); // platform mode: unbound, scope fails closed.
            }

            $shop = Shop::query()->whereKey($enteredShopId)->first();

            if ($shop === null) {
                // Stale selection (e.g. the shop was hard-deleted by shop/redact).
                // Drop it and fall back to platform mode rather than 403 the owner.
                PlatformContext::exit();

                return $next($request);
            }

            return $this->bindForRequest($shop, $request, $next);
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

        return $this->bindForRequest($shop, $request, $next);
    }

    /**
     * Bind the shop for THIS request and continue. Critically, we do NOT clear in a
     * `finally` around $next: Livewire re-runs persistent middleware through a pipeline
     * whose $next is a NO-OP (it only "applies" the binding — see Livewire's
     * Utils::applyMiddleware), so a finally-clear would unbind the tenant the instant
     * that no-op returns — BEFORE the component hydrates and re-checks canAccess() →
     * a 403 on every table/header/form action of an entered platform admin or a
     * direct-login merchant. Instead we clear on app `terminate()` — the real end of the
     * request, after the response is sent — so the binding lasts through hydrate + render,
     * yet a long-lived worker (Octane/FrankenPHP) still never leaks it to the next request.
     */
    private function bindForRequest(Shop $shop, Request $request, Closure $next): Response
    {
        Tenant::set($shop);

        app()->terminating(static fn () => Tenant::clear());

        return $next($request);
    }
}
