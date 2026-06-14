<?php

/**
 * PayPlus OPERATIONAL config — platform defaults only. NO secrets here.
 *
 * Per-shop credentials (api_key, secret_key, terminal_uid, cashier_uid,
 * payment_page_uid, webhook_secret, base_url override) live ENCRYPTED on the
 * `shops` row and are read via Shop::payplusConfig(). The gateway holds them as
 * constructor state. Only the keys below — timeouts, the REST path prefix,
 * retry backoff, VAT, document-type names, billing windows — are platform-wide.
 */
return [

    // Default REST base URL. A shop may override per-row (sandbox vs production).
    'base_url' => env('PAYPLUS_BASE_URL_DEFAULT', 'https://restapi.payplus.co.il'),
    'base_url_sandbox' => env('PAYPLUS_BASE_URL_SANDBOX', 'https://restapidev.payplus.co.il'),

    // REST path prefix in front of resource paths (e.g. /api/v1.0/Transactions/Charge).
    'api_prefix' => env('PAYPLUS_API_PREFIX', '/api/v1.0'),

    // HTTP client timeout, seconds.
    'timeout' => (int) env('PAYPLUS_TIMEOUT', 30),

    // Currency + VAT (platform default; merchant may override in settings later).
    'currency' => env('PAYPLUS_CURRENCY', 'ILS'),
    'vat_rate' => (float) env('PAYPLUS_VAT_RATE', 0.18),

    /*
    | Failed-charge retry backoff, in HOURS, indexed by attempt number (1-based).
    | Reference engine: [4h, 24h, 72h]. After the final attempt the ledger row +
    | payment transition to `failed` (no further retry scheduled).
    */
    'retry_backoff_hours' => [4, 24, 72],

    // Window (hours) into the future the scheduler treats a plan as "due now".
    'charge_window_hours' => (int) env('PAYPLUS_CHARGE_WINDOW_HOURS', 1),

    // Completion threshold: installments plan is "fully paid" when remaining <= this.
    'completion_epsilon' => 0.005,

    /*
    | PayPlus "books" document type names, by logical kind. The DocumentPolicy
    | maps a (charge_context, plan_kind) to one of these — the orchestrator NEVER
    | hardcodes a type. Per-shop overrides land in merchant settings later (3.x).
    */
    'document_types' => [
        'tax_invoice' => env('PAYPLUS_DOC_TAX_INVOICE', 'invoice_receipt'),
        'receipt' => env('PAYPLUS_DOC_RECEIPT', 'receipt'),
        'refund' => env('PAYPLUS_DOC_REFUND', 'credit_invoice'),
        'none' => null,
    ],
];
