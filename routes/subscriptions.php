<?php

use App\Domain\Account\CustomerSubscriptionActions;
use App\Domain\ShopifySubscriptions\Http\CustomerContractController;
use App\Http\Controllers\Shopify\Account\ShopifyAccountController;
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
 * ONE transport for reads AND verbs: the extension bootstraps the whole
 * personal area from GET /account (the AccountPresenter payload — contracts
 * included) and posts every verb back here. The old shopify.query contracts
 * read is retired; the payload is the single source the page draws from.
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
        // Card update: Shopify emails the shopper its own secure card page —
        // the one verb with no merchant switch (it moves no money).
        Route::match(['post', 'options'], '/card-update', [CustomerContractController::class, 'cardUpdate'])
            ->name('subscriptions.card_update');

        // The personal area (PayPlus rail) for the same extension: ONE bootstrap
        // payload (AccountPresenter — the model the WooCommerce area renders),
        // and the same customer verbs, keyed to the token's `sub` customer. The
        // GET carries the Authorization header, so it needs the OPTIONS
        // preflight too.
        Route::match(['get', 'options'], '/account', [ShopifyAccountController::class, 'bootstrap'])
            ->name('subscriptions.account');
        Route::match(['post', 'options'], '/account/{action}', [ShopifyAccountController::class, 'action'])
            ->whereIn('action', CustomerSubscriptionActions::ACTIONS)
            ->name('subscriptions.account.act');
    });
