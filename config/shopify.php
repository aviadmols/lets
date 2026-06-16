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
    */
    'oauth_scopes' => env(
        'SHOPIFY_OAUTH_SCOPES',
        'read_orders,write_orders,read_draft_orders,write_draft_orders,'.
        'read_customers,read_products,read_fulfillments,write_fulfillments,'.
        'read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders'
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
        'installments_active' => 'installment_plan_active',
        'installments_hold' => 'installments-hold',
        'paid_release' => 'installments-paid',
        'ready_to_fulfill' => 'installments-ready',
        'recurring_order' => 'subscription-recurring',
        'upsell_child' => 'upsell-child',
        'payment_order' => 'installments-payment',
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
];
