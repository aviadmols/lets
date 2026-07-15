<?php

/**
 * WooCommerce PLATFORM config — the per-shop WooCommerce/PayPlus edge knobs.
 *
 * Unlike Shopify, WooCommerce shops connect with a per-shop API key + HMAC (no
 * OAuth) and pay deposits on the PayPlus HOSTED page (not a Shopify draft order).
 * The REST credentials, the wc_webhook_secret and the PayPlus credentials all live
 * ENCRYPTED on the `shops` row and are read PER-SHOP — never from this file. What
 * lives here are platform-wide, NON-secret behaviour flags that the same way for
 * every WooCommerce shop.
 *
 * DEFENSIVE-DEFAULT contract: every flag here ships with the value that PRESERVES
 * today's behaviour. The owner verifies each against a REAL PayPlus terminal and
 * only then flips the corresponding env to enforce/adjust. Nothing here changes
 * behaviour until an env is explicitly set.
 *
 * @see app/Http/Controllers/WooCommerce/Storefront/WooDepositCallbackController.php
 * @see app/Http/Controllers/WooCommerce/Storefront/WooGatewayCallbackController.php
 * @see app/Services/WooCommerce/Orders/WooCommerceDepositInvoiceService.php
 */
return [

    // === Per-shop REST Admin client knobs ===

    /*
    | HTTP timeout (seconds) for WooClientFactory's per-shop WC REST client. Already
    | consumed by WooClientFactory; declared here so the default is explicit in one
    | place rather than an implicit config(...) fallback.
    */
    'timeout' => (int) env('WOOCOMMERCE_HTTP_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | require_callback_signature  (FOLLOW-UP 1 — defensive, default FALSE)
    |--------------------------------------------------------------------------
    |
    | PayPlus → SaaS callbacks (deposit + gateway "mode B") carry an OPTIONAL `hash`
    | header = base64(HMAC-SHA256(rawBody, secret_key)). Today we verify it ONLY when
    | present (a present-but-WRONG signature fails closed; an ABSENT one falls through
    | to the money-gated activation, because not every PayPlus account is known to sign
    | WC callbacks yet).
    |
    |   FALSE (default, current behaviour): optional-HMAC — a callback with NO `hash`
    |          header is still processed (money stays gated server-side; a callback can
    |          only ever activate the plan it names, once, for the amount we computed).
    |
    |   TRUE  (verified-enforce): MANDATORY-HMAC — a callback that LACKS a valid
    |          signature is rejected 401 and never processed. Flip this ONLY after the
    |          owner has confirmed, against a real PayPlus terminal, that PayPlus DOES
    |          sign the WC deposit/gateway callbacks (otherwise every legitimate callback
    |          would 401 and no deposit plan would ever activate).
    |
    | An empty per-shop secret_key while this flag is TRUE returns 503 (fail-closed:
    | we cannot verify, so we refuse) — identical to the platform HMAC rule.
    */
    'require_callback_signature' => (bool) env('WOOCOMMERCE_REQUIRE_CALLBACK_SIGNATURE', false),

    /*
    |--------------------------------------------------------------------------
    | charge_method  (W17 — default 1 = immediate capture)
    |--------------------------------------------------------------------------
    |
    | The PayPlus generateLink charge_method passed when creating EVERY PayPlus page
    | (normal gateway checkout, deposit, and the subscription first payment). PayPlus:
    |     1 = immediate CHARGE/capture — money is taken now and the card token vaulted.
    |     0 = authorize/verify-only — a "success" screen but NO capture (the W17 bug:
    |         orders showed paid on-screen yet stayed unpaid with nothing in the PayPlus
    |         dashboard). This was the old default; 1 is now correct.
    |
    | Config-driven (not a hardcoded literal) so a terminal that uses a different code for
    | an immediate charge can be re-tuned with an env flip, not a deploy. If a live test
    | shows 1 still doesn't capture on a specific terminal, try 2.
    */
    'charge_method' => (int) env('WOOCOMMERCE_CHARGE_METHOD', 1),

];
