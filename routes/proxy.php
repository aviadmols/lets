<?php

use App\Domain\Installments\Http\Controllers\Storefront\InstallmentModalController;
use App\Domain\Installments\Http\Controllers\Storefront\InstallmentQuoteController;
use App\Domain\Installments\Http\Controllers\Storefront\StartInstallmentPlanController;
use App\Domain\Upsell\Http\Controllers\ProxyOfferController;
use App\Http\Middleware\VerifyShopifyAppProxy;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shopify App Proxy routes (the extension → backend seam)
|--------------------------------------------------------------------------
| Mounted at /proxy (shopify.app.toml [app_proxy].url = https://app.lets.co.il
| /proxy). The storefront / checkout / post-purchase extensions reach these via
| https://{shop}/apps/payplus/... which Shopify proxies here with a `signature`
| query param.
|
| VerifyShopifyAppProxy is the AUTH: it verifies the Shopify signature (fail
| closed — 401 bad/absent, 503 if the platform secret is empty in production),
| resolves the Shop from the verified `shop` param, and binds the Tenant for the
| request. Storefront JS / extensions can NEVER assert a shop id themselves.
|
| Stateless JSON; no session, no CSRF. The accept ACTION is a separate SIGNED
| route (routes/upsell.php → upsell.accept.api) whose signed URL this offer
| endpoint hands back, so the charge path reuses the proven signed-link auth +
| idempotency verbatim.
*/
Route::prefix('proxy')
    ->middleware(VerifyShopifyAppProxy::class)
    ->group(function () {
        // GET eligible post-purchase / thank-you offer for the signed shop + the
        // purchased-product context. Returns the offer JSON (server-computed price)
        // + signed accept/decline URLs, or { offer: null } when nothing matches.
        Route::get('/upsell/offer', ProxyOfferController::class)->name('proxy.upsell.offer');

        /*
        |----------------------------------------------------------------------
        | Deposit + installments storefront entry (W9 Part C)
        |----------------------------------------------------------------------
        | The product-page button loads /modal/... in an <iframe>; that page
        | previews schedules via /quote and starts a plan via /start — ALL three
        | proxied + signed here, so the shop is server-derived every time and the
        | money is computed server-side (the client only picks knobs + a variant).
        */

        // Deposit-calculator page (iframe content). The {productGid}/{variantGid}
        // segments are BARE NUMERIC Shopify ids (the button sends numeric ids to
        // avoid %2F-in-path pitfalls); the controller rebuilds the canonical gid://
        // and resolves the TRUSTED price from our synced catalog cache.
        Route::get('/installments/modal/{productGid}/{variantGid}', InstallmentModalController::class)
            ->where(['productGid' => '[0-9]+', 'variantGid' => '[0-9]+'])
            ->name('proxy.installments.modal');

        // Live schedule PREVIEW (no plan, no charge) — server-computed money.
        Route::post('/installments/quote', InstallmentQuoteController::class)
            ->name('proxy.installments.quote');

        // Create the plan + UNPAID deposit invoice; returns the invoice URL to redirect.
        Route::post('/installments/start', StartInstallmentPlanController::class)
            ->name('proxy.installments.start');
    });
