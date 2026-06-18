<?php

use App\Domain\Upsell\Http\Controllers\AcceptUpsellApiController;
use App\Domain\Upsell\Http\Controllers\AcceptUpsellController;
use App\Domain\Upsell\Http\Controllers\DeclineUpsellController;
use App\Domain\Upsell\Http\Controllers\DevPreviewUpsellController;
use App\Domain\Upsell\Http\Controllers\SessionTokenOfferController;
use App\Domain\Upsell\Http\Controllers\ThankYouUpsellController;
use App\Domain\Upsell\UpsellSignedUrlService;
use Illuminate\Support\Facades\Route;

/*
 * Post-purchase / thank-you-page UPSELL storefront routes (Phase 6, the third
 * pillar). All are SIGNED — the signature is the auth (the storefront has no
 * admin session). Tenant is bound from the signed shop id inside each controller
 * and cleared after. The accept/decline action names MUST match
 * UpsellSignedUrlService so signed links verify.
 *
 * The accept route is idempotent by its deterministic ledger key, so a
 * double-clicked one-click link collapses to exactly ONE charge.
 */
Route::middleware(['signed'])->prefix('upsell')->group(function () {
    // The thank-you widget: resolve + render the offer for a purchase.
    Route::get('/widget', ThankYouUpsellController::class)->name('upsell.widget');

    // One-click action links rendered inside the widget.
    Route::get('/accept', AcceptUpsellController::class)->name(UpsellSignedUrlService::ROUTE_ACCEPT);
    Route::get('/decline', DeclineUpsellController::class)->name(UpsellSignedUrlService::ROUTE_DECLINE);
});

// JSON twin of /accept for the checkout/post-purchase EXTENSIONS (they consume
// JSON, not an HTML view). Same signed-link auth, same UpsellChargeService, same
// idempotency — only the response shape differs. GET + POST so an extension can
// fetch() with either verb against the one signed URL.
//
// extension.cors runs BEFORE `signed` (route-middleware order) so the CROSS-ORIGIN
// OPTIONS preflight short-circuits in CORS — it carries no signature, so it must
// not reach the `signed` gate. The real GET/POST still passes through `signed`
// (the URL signature is the auth). The extension runs in a sandboxed worker
// origin, not app.lets.co.il, hence the cross-origin handling.
Route::match(['get', 'post', 'options'], 'upsell/accept-api', AcceptUpsellApiController::class)
    ->middleware(['extension.cors', 'signed'])
    ->name(UpsellSignedUrlService::ROUTE_ACCEPT_API);

/*
 * SESSION-TOKEN offer endpoint for the checkout/customer-account UI EXTENSIONS.
 *
 * Those targets (purchase.thank-you.block.render,
 * customer-account.order-status.block.render) run in a sandboxed worker with no
 * storefront origin/session, so the relative App-Proxy fetch cannot resolve. They
 * instead DIRECT-fetch this absolute URL with a session-token (JWT) bearer.
 *   - shopify.session  → verifies the JWT + binds the tenant from the `dest` shop;
 *   - extension.cors   → Access-Control-Allow-Origin:* + the OPTIONS preflight, so
 *                        the cross-origin fetch is exposed to the extension.
 * The response shape matches the App-Proxy offer endpoint (offer + ABSOLUTE signed
 * accept_api_url). The App-Proxy route (routes/proxy.php) stays as the storefront
 * fallback. Stateless JSON — no session/CSRF (the JWT bearer is the auth).
 */
Route::prefix('upsell')
    ->middleware(['extension.cors', 'shopify.session'])
    ->group(function () {
        Route::match(['get', 'options'], '/offer', SessionTokenOfferController::class)
            ->name('upsell.offer.session');
    });

/*
 * DEV-ONLY widget preview for the "View post-purchase" drawer button. NOT signed
 * — gated hard by app()->isLocal() AND config('app.dev_tenant'), the same gate as
 * DevAutoLogin, so it can never exist on a production deploy. Renders the live
 * thank-you widget for one tenant-scoped offer (no charge, no event recorded).
 */
if (app()->isLocal() && config('app.dev_tenant', false)) {
    Route::get('/upsell/preview/{offer}', DevPreviewUpsellController::class)
        ->whereNumber('offer')
        ->name('upsell.dev_preview');
}
