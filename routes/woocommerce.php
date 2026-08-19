<?php

use App\Domain\Account\CustomerSubscriptionActions;
use App\Http\Controllers\WooCommerce\Account\AccountActionController;
use App\Http\Controllers\WooCommerce\Account\AccountBootstrapController;
use App\Http\Controllers\WooCommerce\Account\AccountOtpController;
use App\Http\Controllers\WooCommerce\Account\ImpersonateVerifyController;
use App\Http\Controllers\WooCommerce\Account\SubscriptionEligibilityController;
use App\Http\Controllers\WooCommerce\CheckoutSettingsController;
use App\Http\Controllers\WooCommerce\DiagnosticsController;
use App\Http\Controllers\WooCommerce\EmbedSessionController;
use App\Http\Controllers\WooCommerce\InstallController;
use App\Http\Controllers\WooCommerce\InvoicingController;
use App\Http\Controllers\WooCommerce\PortalSettingsController;
use App\Http\Controllers\WooCommerce\Storefront\WooDepositCallbackController;
use App\Http\Controllers\WooCommerce\Storefront\WooDepositReturnController;
use App\Http\Controllers\WooCommerce\Storefront\WooGatewayCallbackController;
use App\Http\Controllers\WooCommerce\Storefront\WooGatewaySessionController;
use App\Http\Controllers\WooCommerce\Storefront\WooGatewayVerifyController;
use App\Http\Controllers\WooCommerce\Storefront\WooInstallmentQuoteController;
use App\Http\Controllers\WooCommerce\Storefront\WooLoyaltyPageUrlController;
use App\Http\Controllers\WooCommerce\Storefront\WooStartInstallmentPlanController;
use App\Http\Controllers\WooCommerce\Storefront\WooSubscribeController;
use App\Http\Controllers\WooCommerce\Storefront\WooSubscriptionCatalogController;
use App\Http\Controllers\WooCommerce\Storefront\WooUpsellAcceptController;
use App\Http\Controllers\WooCommerce\Storefront\WooUpsellDeclineController;
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
        | "LETS" inside wp-admin — the embedded admin handshake
        |----------------------------------------------------------------------
        | The plugin's SERVER asks for a one-shot, 60-second URL and renders it in
        | an iframe; the merchant is signed into the Filament panel without ever
        | having a password. The shop is the HMAC-verified shop — a shop_id in the
        | body is ignored — so a merchant can only ever mint a door into their own
        | store. Redemption is the WEB route embed/woocommerce/{token}.
        */
        Route::post('/embed/session', EmbedSessionController::class)
            ->name('woocommerce.embed.session');

        /*
        |----------------------------------------------------------------------
        | The personal area's own settings — banners, appearance, copy
        |----------------------------------------------------------------------
        | Same contract as checkout-settings, for the surface the SHOPPER sees:
        | the merchant edits in WordPress, LETS stores per shop, and the Filament
        | preview and the storefront read the same row — so the preview cannot
        | show a page the shopper will not get. Merchant input either way: every
        | field is allow-listed, clamped, and https-forced server-side.
        */
        Route::get('/portal-settings', [PortalSettingsController::class, 'show'])
            ->name('woocommerce.portal_settings.show');
        Route::post('/portal-settings', [PortalSettingsController::class, 'update'])
            ->name('woocommerce.portal_settings.update');

        /*
        |----------------------------------------------------------------------
        | Green Invoice (Morning) invoicing — the `all_orders` scope
        |----------------------------------------------------------------------
        | LETS only ever sees orders that went through a LETS flow. A merchant who
        | wants documents for EVERY order on their site needs the plugin to report the
        | rest, so: /invoicing-settings tells the plugin's order hook whether to fire
        | at all (a `plans_only` shop costs zero HTTP calls), and /orders/issue-document
        | reports one paid order. Both re-check the merchant's scope + statuses
        | SERVER-SIDE — the plugin's cached settings can be stale, and a status the
        | merchant did not pick must never mint a tax document.
        */
        Route::get('/invoicing-settings', [InvoicingController::class, 'settings'])
            ->name('woocommerce.invoicing.settings');
        Route::post('/orders/issue-document', [InvoicingController::class, 'issue'])
            ->name('woocommerce.invoicing.issue');
        // READ: the issued documents for one order (admin metabox + customer link).
        // POST, not GET — the plugin's GET signer excludes the query string.
        Route::post('/orders/documents', [InvoicingController::class, 'documents'])
            ->name('woocommerce.invoicing.documents');

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
        | Cart-based subscription products (W17 Part B)
        |----------------------------------------------------------------------
        | The plugin marks WC products that have an active LETS subscription template
        | and, on add-to-cart → normal checkout, the LETS gateway starts a recurring
        | plan per the template. /flags = which products are subscriptions (bulk);
        | /config = the resolved template + server-computed per-cycle price.
        */
        Route::post('/subscriptions/flags', [WooSubscriptionCatalogController::class, 'flags'])
            ->name('woocommerce.subscriptions.flags');
        Route::post('/subscriptions/config', [WooSubscriptionCatalogController::class, 'config'])
            ->name('woocommerce.subscriptions.config');

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
        Route::post('/upsell/decline', WooUpsellDeclineController::class)
            ->name('woocommerce.upsell.decline');

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
        // Verify-on-return: the plugin confirms a gateway payment from the thank-you page when
        // PayPlus didn't push the callback (orders stuck "pending"). Same HMAC group.
        Route::post('/gateway/verify', WooGatewayVerifyController::class)
            ->name('woocommerce.gateway.verify');

        // Loyalty club: the plugin's SERVER tells us who is logged in (over this
        // HMAC group) and we hand back a short-lived signed URL for exactly that
        // shopper, which the plugin renders in an iframe. WooCommerce has no App
        // Proxy, so this is how identity is established without trusting a browser.
        Route::post('/loyalty/page-url', WooLoyaltyPageUrlController::class)
            ->name('woocommerce.loyalty.page_url');

        /*
        |----------------------------------------------------------------------
        | The shopper's personal area (My Account)
        |----------------------------------------------------------------------
        | /bootstrap returns the WHOLE area as one JSON view-model — sections,
        | appearance, banners, subscriptions, the benefit timeline, loyalty and
        | every already-translated string. The plugin renders it natively into the
        | page (not an iframe) so it inherits the theme's font.
        |
        | Identity is asserted by the plugin's SERVER over this HMAC group, the
        | same way the loyalty rail does it; the shopper's browser never holds the
        | secret. Every endpoint is a POST because the plugin's GET signer excludes
        | the query string — identity in a GET would travel unsigned.
        |
        | The OTP pair deliberately does NOT log anyone in: /verify answers "did
        | this code match this destination" and the plugin performs the WordPress
        | login itself, so WP stays the authority on identity.
        */
        Route::post('/account/bootstrap', AccountBootstrapController::class)
            ->name('woocommerce.account.bootstrap');

        Route::post('/account/otp/request', [AccountOtpController::class, 'request'])
            ->name('woocommerce.account.otp.request');
        Route::post('/account/otp/verify', [AccountOtpController::class, 'verify'])
            ->name('woocommerce.account.otp.verify');

        // "Log in as this customer", from the LETS customer page. The admin's
        // browser arrives at the store carrying a ticket; the plugin hands it back
        // here and we answer WHO it stands for. It is an attestation, never a
        // session: WordPress resolves its own user and refuses privileged accounts.
        // Single use, two minutes, and only for the shop the signature resolved.
        Route::post('/account/impersonate/verify', ImpersonateVerifyController::class)
            ->name('woocommerce.account.impersonate.verify');

        Route::post('/account/subscriptions/{action}', AccountActionController::class)
            ->whereIn('action', CustomerSubscriptionActions::ACTIONS)
            ->name('woocommerce.account.subscriptions.act');

        // Asked by the STOREFRONT, not the account page: may this shopper start
        // another subscription? Same HMAC group because the answer depends on who
        // the plugin's server says is buying.
        Route::post('/account/subscription-eligibility', SubscriptionEligibilityController::class)
            ->name('woocommerce.account.subscription_eligibility');
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
