<?php

use App\Domain\Portal\Http\Controllers\PortalController;
use App\Domain\Portal\PortalSignedUrlService;
use Illuminate\Support\Facades\Route;

/*
 * CUSTOMER PORTAL storefront routes (Phase 6.5, the self-service magic link). All
 * are SIGNED — the signature is the only auth (the portal has NO admin session).
 * A signed link binds {shop, plan, customer}; the controller binds the tenant from
 * the signed shop, proves the signed customer owns the entry plan, then scopes +
 * filters every query to that customer on that shop. Cross-customer / cross-shop
 * access is therefore impossible (see PortalController + the feature tests).
 *
 * The action route names MUST match PortalSignedUrlService so signed links verify.
 * Pause/resume/cancel are POST + signed; the signature covers the query string
 * (shop/plan/customer), and the controller re-verifies the body's target plan
 * against the signed customer's scoped set before any state transition.
 */
Route::middleware(['signed'])->prefix('portal')->group(function () {
    Route::get('/', [PortalController::class, 'show'])->name(PortalSignedUrlService::ROUTE_SHOW);

    Route::post('/pause', [PortalController::class, 'pause'])->name(PortalSignedUrlService::ROUTE_PAUSE);
    Route::post('/resume', [PortalController::class, 'resume'])->name(PortalSignedUrlService::ROUTE_RESUME);
    Route::post('/cancel', [PortalController::class, 'cancel'])->name(PortalSignedUrlService::ROUTE_CANCEL);
});
