<?php

use App\Domain\Loyalty\Http\Controllers\LoyaltyActionController;
use App\Domain\Loyalty\Http\Controllers\LoyaltyPageController;
use App\Domain\Loyalty\Http\Controllers\LoyaltyRedeemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loyalty club — the SIGNED (WooCommerce) rail
|--------------------------------------------------------------------------
| WooCommerce has no App Proxy, so the plugin cannot hand us a signature the
| way Shopify does. Instead the plugin's SERVER calls us over HMAC
| (/api/woocommerce/loyalty/page-url), we mint a short-lived Laravel signed URL
| carrying the shop and the logged-in customer's reference, and the plugin
| renders it in an iframe.
|
| The signature is the whole credential: shop id and customer reference live
| INSIDE it, so a shopper cannot edit the query to read someone else's balance —
| any tampering invalidates the signature and Laravel 403s before we run.
|
| Mounted group-less (no session, no CSRF) in bootstrap/app.php, exactly like
| routes/proxy.php: these are cross-origin iframe requests from the merchant's
| own domain, and the signature — not a cookie — is what authenticates them.
*/
Route::prefix('loyalty/{shop}')
    ->middleware('signed')
    ->whereNumber('shop')
    ->group(function (): void {
        Route::get('/', LoyaltyPageController::class)->name('loyalty.signed.page');
        Route::post('/join', [LoyaltyActionController::class, 'join'])->name('loyalty.signed.join');
        Route::post('/birthday', [LoyaltyActionController::class, 'birthday'])->name('loyalty.signed.birthday');
        Route::post('/social', [LoyaltyActionController::class, 'social'])->name('loyalty.signed.social');
        Route::post('/redeem', [LoyaltyRedeemController::class, 'redeem'])->name('loyalty.signed.redeem');
    });
