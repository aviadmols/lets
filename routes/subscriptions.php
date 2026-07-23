<?php

use App\Domain\ShopifySubscriptions\Http\CustomerContractController;
use Illuminate\Support\Facades\Route;

/*
 * The customer personal area's ACTION endpoints (the Shopify-Payments rail).
 *
 * Called by the customer-account FULL-PAGE extension (customer-account.page.render)
 * with a session-token (JWT) bearer — the same transport contract as the upsell
 * extension: the sandboxed worker has no storefront origin/session, so it
 * DIRECT-fetches these absolute URLs.
 *   - shopify.session  → verifies the JWT + binds the tenant from the `dest` shop;
 *   - extension.cors   → Access-Control-Allow-Origin + the OPTIONS preflight.
 * The controller then matches the token's `sub` (the logged-in customer) against
 * the contract's owner, so a shopper can act on THEIR subscription only.
 *
 * READS are deliberately absent: the extension reads contracts via shopify.query
 * on the Customer Account API, which scopes to the logged-in customer natively.
 * Stateless JSON — no session/CSRF (the JWT bearer is the auth).
 */
Route::prefix('subscriptions/api')
    ->middleware(['extension.cors', 'shopify.session'])
    ->group(function () {
        Route::match(['post', 'options'], '/pause', [CustomerContractController::class, 'pause'])
            ->name('subscriptions.pause');
        Route::match(['post', 'options'], '/resume', [CustomerContractController::class, 'resume'])
            ->name('subscriptions.resume');
        Route::match(['post', 'options'], '/skip', [CustomerContractController::class, 'skip'])
            ->name('subscriptions.skip');
        Route::match(['post', 'options'], '/reschedule', [CustomerContractController::class, 'reschedule'])
            ->name('subscriptions.reschedule');
        Route::match(['post', 'options'], '/cancel', [CustomerContractController::class, 'cancel'])
            ->name('subscriptions.cancel');
    });
