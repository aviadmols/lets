<?php

/**
 * Shopify PLATFORM config — public App-Store-distributed app.
 *
 * This holds the ONE platform app's identity (api_key/secret), the pinned API
 * version, the OAuth scopes, and the platform webhook secret. There is NO
 * `shpat_…` admin token here: this is NOT a custom app. Every shop's offline
 * access token is captured at OAuth install and stored ENCRYPTED on its `shops`
 * row, then read per-shop via Shop::shopifyAccessToken() — never from config.
 *
 * App-level webhooks are signed with SHOPIFY_API_SECRET (the app secret). Shopify
 * exposes it to the platform as SHOPIFY_WEBHOOK_SECRET; when that env is unset we
 * fall back to the api_secret so a single secret drives both OAuth-HMAC and
 * webhook-HMAC verification (they are the same secret for app-level webhooks).
 *
 * PayPlus callbacks are the OPPOSITE — those carry a PER-SHOP webhook_secret and
 * are owned/verified by laravel-backend, not here.
 *
 * @see app/Services/Shopify/ShopifyClientFactory.php — builds a per-shop client.
 * @see app/Http/Middleware/VerifyShopifyWebhook.php  — raw-body HMAC, fail closed.
 */
return [

    // === Platform app identity (public distribution) ===
    'api_key' => env('SHOPIFY_API_KEY', ''),
    'api_secret' => env('SHOPIFY_API_SECRET', ''),

    /*
    | EVERY Shopify Partner app this ONE deployment serves, keyed by app key.
    | Stage 1: real test stores install through the CUSTOM app (no App Store
    | review needed); the PUBLIC app stays untouched until submission. Both point
    | at the same application_url — the shop row remembers which app installed it
    | (shops.shopify_app_key) and ShopifyApps resolves the right credentials for
    | OAuth, token exchange, session-token `aud`, webhook HMAC and App Bridge.
    | An app whose api_key/api_secret are empty is simply NOT configured — every
    | resolver skips it, so the public-only production setup needs no new env.
    */
    'apps' => [
        'public' => [
            'api_key' => env('SHOPIFY_API_KEY', ''),
            'api_secret' => env('SHOPIFY_API_SECRET', ''),
            'handle' => env('SHOPIFY_APP_HANDLE', 'lets'),
            'oauth_scopes' => env(
                'SHOPIFY_OAUTH_SCOPES',
                'read_orders,write_orders,read_draft_orders,write_draft_orders,'.
                'read_customers,write_customers,read_products,read_fulfillments,write_fulfillments,'.
                'read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,'.
                'write_store_credit_accounts,write_discounts'
            ),
        ],
        'custom' => [
            'api_key' => env('SHOPIFY_CUSTOM_API_KEY', ''),
            'api_secret' => env('SHOPIFY_CUSTOM_API_SECRET', ''),
            'handle' => env('SHOPIFY_CUSTOM_APP_HANDLE', 'lets-subscriptions'),
            // Union: everything the public app does PLUS the Shopify-Payments
            // subscriptions rail (a custom app has no App-Store scope review).
            /*
            | Must stay in lockstep with shopify.app.subscriptions.toml — the toml
            | is what Shopify VALIDATES; this is what the OAuth link REQUESTS, and
            | a mismatch means the merchant grants one set and the app expects
            | another. read/write_purchase_options is what creates selling plans
            | (Shopify names it as the alternative to write_own_subscription_
            | contracts) and is NOT gated behind an API-access request.
            |
            | The subscription scopes joined this list on 2026-07-28, when Shopify
            | approved "Access subscriptions APIs" + "Read all orders scope". This
            | set being WIDER than a shop's stored grant is what makes
            | EmbeddedAuthenticate re-exchange on the next embedded load
            | (ShopifyApps::missingScopes) — i.e. adding them here is what actually
            | upgrades the installed store's token.
            */
            'oauth_scopes' => env(
                'SHOPIFY_CUSTOM_OAUTH_SCOPES',
                'read_customers,write_customers,read_products,write_products,'.
                'read_purchase_options,write_purchase_options,'.
                'write_orders,read_all_orders,write_draft_orders,write_fulfillments,'.
                'write_merchant_managed_fulfillment_orders,'.
                'read_own_subscription_contracts,write_own_subscription_contracts,'.
                'read_customer_payment_methods,write_store_credit_accounts,write_discounts'
            ),
        ],
    ],

    /*
    | Pinned Admin API version. Drives BOTH the REST and GraphQL URL path
    | (/admin/api/{version}/graphql.json). Bump in EXACTLY this one place each
    | quarter (Shopify ships Jan/Apr/Jul/Oct; each stable version is supported
    | >=12 months). Before a bump: read that version's release notes for breaking
    | changes to orders/draftOrders/fulfillmentOrders/webhookSubscription, run the
    | integration tests against a sandbox shop, then promote.
    */
    'api_version' => env('SHOPIFY_API_VERSION', '2026-04'),

    // Public URL of THIS app (the platform callback host for OAuth + webhooks).
    'app_url' => rtrim((string) env('SHOPIFY_APP_URL', env('APP_URL', '')), '/'),

    /*
    | OAuth scopes requested at install. App Store reviewers check that every
    | scope maps to a real call. Keep minimal; add a scope only when a feature
    | actually needs it. Mirrors SHOPIFY_OAUTH_SCOPES in .env.example.
    |
    | Two scopes serve the loyalty club:
    |   write_store_credit_accounts — redemption (ShopifyStoreCreditIssuer) turns
    |     points into real store credit on the shopper's account;
    |   write_discounts — a member's referral code is published as a real
    |     discount code (ReferralDiscountPublisher), which is what makes the
    |     shared link work and the friend's order carry the attribution.
    | A shop installed BEFORE they were added holds a narrower grant —
    | EmbeddedAuthenticate re-exchanges on the next embedded load
    | (ShopifyApps::missingScopes). Until then both features degrade quietly:
    | redemption says "not right now" and keeps the points, and the share card
    | is simply not offered rather than handing out a code checkout would reject.
    */
    'oauth_scopes' => env(
        'SHOPIFY_OAUTH_SCOPES',
        'read_orders,write_orders,read_draft_orders,write_draft_orders,'.
        'read_customers,write_customers,read_products,read_fulfillments,write_fulfillments,'.
        'read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,'.
        'write_store_credit_accounts,write_discounts'
    ),

    /*
    | Platform webhook signing secret. App-level webhooks are HMAC-signed with the
    | app secret; if SHOPIFY_WEBHOOK_SECRET is not separately provisioned, fall
    | back to api_secret (they are the same value for app-level subscriptions).
    | VerifyShopifyWebhook fails CLOSED (503) when this is empty in production.
    */
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET', '')),

    /*
    | ONE platform endpoint for all shops' webhooks. Shopify routes by header
    | (X-Shopify-Shop-Domain); we resolve the Shop row from that header AFTER the
    | HMAC proves the platform sent it.
    */
    'webhook_address' => rtrim((string) env('SHOPIFY_APP_URL', env('APP_URL', '')), '/').'/shopify/webhooks',

    /*
    | Webhook topics registered on install (per-shop, idempotently). The three
    | privacy topics are MANDATORY for App Store approval and are ALSO declared in
    | shopify.app.toml so Shopify validates them at config-push time.
    */
    'webhook_topics' => [
        'orders/paid',
        'orders/create',
        'orders/cancelled',
        'orders/fulfilled',
        'refunds/create',
        'app/uninstalled',
        // Catalog sync — keep the local products cache fresh (W1).
        'products/create',
        'products/update',
        'products/delete',
        // Mandatory privacy webhooks (GDPR / Shopify Protected Customer Data).
        'customers/redact',
        'shop/redact',
        'customers/data_request',
    ],

    /*
    | EXTRA topics registered only for shops whose merchant chose the
    | Shopify-Payments subscriptions rail (Settings → Billing). Registration
    | requires the subscription scopes — i.e. an install through the custom app —
    | so RegisterShopifyWebhooksJob appends these per shop, never globally.
    */
    'subscription_webhook_topics' => [
        'subscription_contracts/create',
        'subscription_contracts/update',
        'subscription_billing_attempts/success',
        'subscription_billing_attempts/failure',
        'subscription_billing_attempts/challenged',
    ],

    /*
    | The case-insensitive request headers Shopify sends on every webhook. Kept in
    | config so a header rename never requires a code change.
    */
    'webhook_headers' => [
        'topic' => 'X-Shopify-Topic',
        'hmac' => 'X-Shopify-Hmac-SHA256',
        'webhook_id' => 'X-Shopify-Webhook-Id',
        'shop_domain' => 'X-Shopify-Shop-Domain',
        'api_version' => 'X-Shopify-API-Version',
        'triggered_at' => 'X-Shopify-Triggered-At',
    ],

    /*
    | Per-shop rate-limit / cost-awareness knobs for the Admin client.
    | REST: leaky bucket (~2 req/s standard); GraphQL: cost-based budget.
    */
    'rest_page_size' => (int) env('SHOPIFY_REST_PAGE_SIZE', 250),
    'rest_max_pages' => (int) env('SHOPIFY_REST_MAX_PAGES', 200),
    'graphql_cost_buffer' => (int) env('SHOPIFY_GRAPHQL_COST_BUFFER', 50),
    'http_timeout' => (int) env('SHOPIFY_HTTP_TIMEOUT', 30),
    'max_retries' => (int) env('SHOPIFY_MAX_RETRIES', 3),

    /*
    | Order-strategy tags + metafields (one namespace). Ported from the reference
    | engine's config('payplus_installments.shopify.*') so the proven Shopify
    | shape survives the multi-tenant refactor.
    */
    'metafield_namespace' => env('SHOPIFY_METAFIELD_NAMESPACE', 'payplus_subscriptions'),
    'tags' => [
        /*
         * The umbrella mark on EVERY order this app creates, beside whatever
         * kind-specific tag the order also carries. A merchant looking at their
         * store needs one filter that answers "which of these did LETS make?" —
         * the per-kind tags below answer a different, narrower question.
         */
        'app' => env('SHOPIFY_APP_ORDER_TAG', 'LETS'),
        'installments_active' => 'installment_plan_active',
        'installments_hold' => 'installments-hold',
        'paid_release' => 'installments-paid',
        'ready_to_fulfill' => 'installments-ready',
        'recurring_order' => 'subscription-recurring',
        'upsell_child' => 'upsell-child',
        /*
         * An order waiting out its post-purchase add-on window.
         *
         * Deliberately NOT installments-hold. The two holds are released by
         * different code on different conditions, and updateOrderTags() replaces
         * the tag list wholesale — sharing one tag would have each release path
         * strip the other's mark and, worse, make an installments order look
         * releasable to the upsell scanner.
         */
        'upsell_hold' => 'upsell-hold',
        'payment_order' => 'installments-payment',
        // A loyalty gift: zero-total, given not sold. Tagged so the merchant can
        // filter these out of revenue reporting at a glance.
        'gift_order' => 'lets-gift',
        // An order whose subscription price is BELOW the regular catalog price —
        // a checkout coupon, an intro-discount window, or a kept first-payment
        // price. Lets the merchant see discounted subscription revenue at a glance.
        'subscription_discount' => 'subscription-discount',
    ],
    'metafields' => [
        'fulfillment_lock' => 'fulfillment_lock',
        'plan_public_id' => 'plan_public_id',
        'installment_status' => 'installment_status',
        'paid_amount' => 'paid_amount',
        'remaining_balance' => 'remaining_balance',
        'next_charge_at' => 'next_charge_at',
    ],

    // Inline-sale-transaction trick (child/recurring orders ONLY — never parent).
    'order_tx_gateway' => env('SHOPIFY_ORDER_TX_GATEWAY', 'manual'),
    'order_tx_source' => env('SHOPIFY_ORDER_TX_SOURCE', 'external'),
    'order_source_name' => env('SHOPIFY_ORDER_SOURCE_NAME', 'payplus-subscriptions'),

    // Where to send the merchant after a successful install (embedded admin).
    // The app ships as "LETS" (handle `lets`) at https://app.lets.co.il.
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'lets'),

    /*
    | App Proxy subpath/prefix — must mirror shopify.app.toml [app_proxy]. The
    | storefront/extension calls https://{shop}/apps/payplus/... which Shopify
    | proxies to https://app.lets.co.il/proxy/... with a `signature` query param.
    | VerifyShopifyAppProxy verifies that signature (fail closed) and derives the
    | shop from the verified `shop` param — never from untrusted client input.
    */
    'app_proxy_prefix' => env('SHOPIFY_APP_PROXY_PREFIX', 'apps'),
    'app_proxy_subpath' => env('SHOPIFY_APP_PROXY_SUBPATH', 'payplus'),

    /*
    | The THEME APP EXTENSION's registration uuid — the only thing that makes a
    | theme-editor deep link (…/editor?context=apps&activateAppId={uuid}/{block})
    | resolve to OUR app block. It MUST track `uid` in
    | extensions/lets-installments/shopify.extension.toml; if the extension is
    | ever re-registered, this value changes with it and the Storefront page's
    | "Add to theme" buttons would otherwise open an empty editor.
    */
    'theme_extension_uuid' => env('SHOPIFY_THEME_EXTENSION_UUID', '45589920-e4d0-8f01-8480-f6f7aafb8f530dfd0fcc'),
];
