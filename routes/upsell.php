<?php

use App\Domain\Upsell\Http\Controllers\AcceptUpsellApiController;
use App\Domain\Upsell\Http\Controllers\AcceptUpsellController;
use App\Domain\Upsell\Http\Controllers\DeclineUpsellController;
use App\Domain\Upsell\Http\Controllers\DevPreviewUpsellController;
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

    // JSON twin of /accept for the checkout/post-purchase EXTENSIONS (they consume
    // JSON, not an HTML view). Same signed-link auth, same UpsellChargeService,
    // same idempotency — only the response shape differs. GET + POST so an
    // extension can fetch() with either verb against the one signed URL.
    Route::match(['get', 'post'], '/accept-api', AcceptUpsellApiController::class)
        ->name(UpsellSignedUrlService::ROUTE_ACCEPT_API);
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
