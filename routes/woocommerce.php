<?php

use App\Http\Controllers\WooCommerce\CheckoutSettingsController;
use App\Http\Controllers\WooCommerce\DiagnosticsController;
use App\Http\Controllers\WooCommerce\InstallController;
use App\Http\Controllers\WooCommerce\Storefront\WooDepositCallbackController;
use App\Http\Controllers\WooCommerce\Storefront\WooDepositReturnController;
use App\Http\Controllers\WooCommerce\Storefront\WooGatewayCallbackController;
use App\Http\Controllers\WooCommerce\Storefront\WooGatewaySessionController;
use App\Http\Controllers\WooCommerce\Storefront\WooInstallmentQuoteController;
use App\Http\Controllers\WooCommerce\Storefront\WooStartInstallmentPlanController;
use App\Http\Controllers\WooCommerce\Storefront\WooSubscribeController;
use App\Http\Controllers\WooCommerce\Storefront\WooUpsellAcceptController;
use App\Http\Controllers\WooCommerce\Storefront\WooUpsellOfferController;
use App\Http\Middleware\VerifyWooCommerceSignature;
use Illuminate\Support\Facades\Route;

/*
 * WooCommerce plugin → SaaS routes. Stateless JSON (no session, no CSRF token — the
 * request comes server-to-server from the WordPress plugin). VerifyWooCommerceSignature
 * is the auth (per-shop API-key HMAC) and binds the tenant from the verified shop. The
 * connect handshake completes the admin-driven onboarding loop; the installments
 * endpoints are the deposit + installments selling flow (W11 P2).
 */
Route::middleware(VerifyWooCommerceSignature::class)
    ->prefix('api/woocommerce')
    ->group(function () {
        Route::post('/install', [InstallController::class, 'install'])->name('woocommerce.install');
        Route::post('/verify-key', [InstallController::class, 'verify'])->name('woocommerce.verify');

        /*
        |----------------------------------------------------------------------
        | Merchant diagnostics for the plugin's Settings → LETS screen (W13)
        |----------------------------------------------------------------------
        | "Test connection" reports what is (and isn't) configured downstream of the
        | HMAC auth; "Test payment page" asks PayPlus for a REAL hosted page and returns
        | its URL — or PayPlus's verbatim rejection. The probe writes no order and no
        | ledger row (see DiagnosticsController).
        */
        Route::post('/diagnostics', [DiagnosticsController::class, 'report'])
            ->name('woocommerce.diagnostics');
        Route::post('/diagnostics/payment-page', [DiagnosticsController::class, 'paymentPage'])
            ->name('woocommerce.diagnostics.payment_page');

        /*
        |----------------------------------------------------------------------
        | PayPlus payment-page options (W15)
        |----------------------------------------------------------------------
        | The merchant edits these in the plugin, but LETS stores them per shop and
        | PayPlusPageOptions applies them to EVERY PayPlus page (checkout, deposit,
        | subscription, probe) so the pages can never drift apart. Every field is
        | allow-listed + clamped server-side — the signed body is still merchant input.
        */
        Route::get('/checkout-settings', [CheckoutSettingsController::class, 'show'])
            ->name('woocommerce.checkout_settings.show');
        Route::post('/checkout-settings', [CheckoutSettingsController::class, 'update'])
            ->name('woocommerce.checkout_settings.update');

        /*
        |----------------------------------------------------------------------
        | Deposit + installments storefront entry (W11 P2)
        |----------------------------------------------------------------------
        | The plugin's product-page widget previews schedules via /quote and starts
        | a plan via /start — both HMAC-signed by the plugin SERVER (the shopper's
        | browser never holds the api_secret). The shop is the HMAC-verified shop on
        | every call; the money is computed server-side (the client picks only knobs +
        | a variation id). /start returns the PayPlus hosted-page URL to redirect to.
        */
        Route::post('/installments/quote', WooInstallmentQuoteController::class)
            ->name('woocommerce.installments.quote');
        Route::post('/installments/start', WooStartInstallmentPlanController::class)
            ->name('woocommerce.installments.start');

        /*
        |----------------------------------------------------------------------
        | Recurring subscriptions storefront entry (W11 P3)
        |----------------------------------------------------------------------
        | The widget's "subscribe" mode posts here. We recompute the per-cycle price
        | server-side, create a recurring plan + the PayPlus first-payment page, and
        | return the page URL. On payment the SAME deposit callback activates the plan;
        | the recurring engine then bills every cycle and materializes a per-cycle WC
        | order via WooCommerceOrderStrategy.
        */
        Route::post('/installments/subscribe', WooSubscribeController::class)
            ->name('woocommerce.installments.subscribe');

        /*
        |----------------------------------------------------------------------
        | Post-purchase / thank-you upsell (W11 P4) — the third pillar
        |----------------------------------------------------------------------
        | The plugin's woocommerce_thankyou widget fetches the eligible offer, then
        | one-click accepts it. /offer resolves via the shared UpsellResolver (records
        | the impression); /accept reuses UpsellChargeService::accept VERBATIM (charges
        | the saved token, consent-gated, idempotent) then records a linked paid WC child
        | order. The shop is the HMAC-verified shop; the amount is server-computed.
        */
        Route::get('/upsell/offer', WooUpsellOfferController::class)
            ->name('woocommerce.upsell.offer');
        Route::post('/upsell/accept', WooUpsellAcceptController::class)
            ->name('woocommerce.upsell.accept');

        /*
        |----------------------------------------------------------------------
        | Full PayPlus gateway, "mode B" (W11 P4) — normal checkout via PayPlus
        |----------------------------------------------------------------------
        | The plugin's WC_Payment_Gateway::process_payment() asks for a PayPlus page for
        | the order total; we return its URL to redirect to. On payment, the gateway
        | callback (below, token-segment auth) marks the WC order paid via WC REST.
        */
        Route::post('/gateway/session', WooGatewaySessionController::class)
            ->name('woocommerce.gateway.session');
    });

/*
 * PayPlus → SaaS deposit-payment surfaces. These are NOT plugin-HMAC-signed (PayPlus,
 * not the plugin, calls them): the opaque {wc_shop_token} path segment resolves the shop
 * BEFORE any field in the body is trusted (the same fail-closed pattern as the WC webhook
 * delivery URL). The callback is server-to-server (CSRF-exempt in bootstrap/app.php);
 * activation records the paid deposit at the plan's STORED amount and is idempotent, so a
 * replayed/forged callback activates a plan at most once.
 */
Route::prefix('woocommerce')->group(function () {
    Route::post('/deposit/callback/{wc_shop_token}', WooDepositCallbackController::class)
        ->name('woocommerce.deposit.callback');
    Route::get('/deposit/return/{wc_shop_token}', WooDepositReturnController::class)
        ->name('woocommerce.deposit.return');

    // Full PayPlus gateway (mode B): PayPlus marks the plain WC order paid here. Same
    // token-segment trust model as the deposit callback (CSRF-exempt in bootstrap/app.php).
    Route::post('/gateway/callback/{wc_shop_token}', WooGatewayCallbackController::class)
        ->name('woocommerce.gateway.callback');
});
