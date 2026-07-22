<?php

/**
 * Invoicing OPERATIONAL config — platform defaults only. NO secrets here.
 *
 * Per-shop provider credentials (api_key_id, api_secret, environment) live
 * ENCRYPTED on the `shops` row and are read via Shop::invoicingConfig(); the
 * provider holds them as constructor state. Only the keys below — base URLs,
 * timeouts, token TTL — are platform-wide and safe to share.
 *
 * The merchant's POLICY (enabled, scope, trigger statuses, document types,
 * delivery) lives per-shop in `merchant_invoicing_settings`, NOT here: two shops
 * on the same deploy must be able to invoice completely differently.
 */
return [

    /*
    | Green Invoice ("Morning"). Docs: https://developers.morning.co
    | The sandbox is a SEPARATE account with separate keys — a merchant testing
    | there must not be able to accidentally mint real tax documents.
    */
    'green_invoice' => [
        'base_url' => env('GREEN_INVOICE_BASE_URL', 'https://api.greeninvoice.co.il/api/v1'),
        'base_url_sandbox' => env('GREEN_INVOICE_BASE_URL_SANDBOX', 'https://sandbox.d.greeninvoice.co.il/api/v1'),
    ],

    // HTTP client timeout, seconds. Kept tight: issuing runs on a queue, so a slow
    // provider must fail fast and be retried, never hold a worker.
    'timeout' => (int) env('INVOICING_TIMEOUT', 20),

    /*
    | JWT cache TTL, seconds. Green Invoice tokens are short-lived; we cache a
    | little UNDER the real lifetime so a token can never expire mid-flight. The
    | response's own expiry wins when it is shorter than this.
    */
    'token_ttl' => (int) env('INVOICING_TOKEN_TTL', 1500),

    // Queue the IssueDocumentJob runs on. Document issuing is never on the charge
    // path — a provider outage must not slow money down. `invoices` is already in
    // the Horizon supervisor's queue list (config/horizon.php).
    'queue' => env('INVOICING_QUEUE', \App\Support\TenantContext::QUEUE_INVOICES),

    // Job retry policy for a transient provider failure (the unique idempotency
    // key makes a retry safe: it reuses the same issued_documents row).
    'job_tries' => (int) env('INVOICING_JOB_TRIES', 3),
    'job_backoff_seconds' => [60, 600],
];
