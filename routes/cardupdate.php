<?php

use App\Domain\Installments\CardUpdateLinks;
use App\Domain\Installments\Http\CardUpdateLandingController;
use App\Http\Controllers\WooCommerce\Storefront\WooCardUpdateCallbackController;
use App\Http\Controllers\WooCommerce\Storefront\WooCardUpdateReturnController;
use Illuminate\Support\Facades\Route;

/*
 * "Update your card" — the durable link a merchant hands to one customer, and
 * the PayPlus rail behind it.
 *
 *   /c/card/{token}                              the landing (GET) + start (POST)
 *   /payplus/cardupdate/callback/{callback_token}  PayPlus → us, server-to-server
 *   /payplus/cardupdate/return/{callback_token}    the shopper's landing after
 *
 * The callback and return routes used to live under `/woocommerce/` keyed on
 * `wc_shop_token`, which quietly made this whole flow unavailable to a SHOPIFY
 * shop charging through the same PayPlus gateway. They are the same handlers,
 * mounted where they belong; the Woo paths stay in routes/woocommerce.php as
 * aliases, because PayPlus can be holding a page URL minted days ago.
 *
 * The landing pair is in the `web` group for the session + CSRF token its one
 * POST button needs. The callback is server-to-server: its auth is the opaque
 * token segment plus the PayPlus signature, and it is CSRF-exempt in
 * bootstrap/app.php.
 */

Route::prefix('c/card')->middleware(['web', 'throttle:card-update-landing'])->group(function (): void {
    Route::get('/{token}', [CardUpdateLandingController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->name(CardUpdateLinks::ROUTE_SHOW);

    Route::post('/{token}', [CardUpdateLandingController::class, 'start'])
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->name(CardUpdateLinks::ROUTE_START);
});

Route::prefix('payplus/cardupdate')->group(function (): void {
    Route::post('/callback/{callback_token}', WooCardUpdateCallbackController::class)
        ->name('payplus.cardupdate.callback');

    Route::get('/return/{callback_token}', WooCardUpdateReturnController::class)
        ->name('payplus.cardupdate.return');
});
