<?php

use App\Domain\Upsell\Http\Controllers\AcceptUpsellController;
use App\Domain\Upsell\Http\Controllers\DeclineUpsellController;
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
