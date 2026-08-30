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
    |--------------------------------------------------------------------------
    | Dunning — what happens when a cycle does not go through
    |--------------------------------------------------------------------------
    |
    | A failed charge is retried ONCE A DAY for `retry_daily_attempts` days, on
    | the SAME payment slot and therefore under the SAME idempotency key — a
    | retry is another attempt at one debt, never a second debt.
    |
    | When the days run out we STOP asking for that cycle. The cycle is skipped
    | and the plan is scheduled for its next ordinary renewal, in the future.
    | It is never collected retroactively: a subscriber who was unreachable for
    | a fortnight must not wake up to a fortnight of back-charges, which is how
    | chargebacks are made. The plan waits in `awaiting_payment` throughout —
    | still a subscriber, still scheduled, visibly unpaid.
    */
    'retry_daily_attempts' => (int) env('PAYPLUS_RETRY_DAILY_ATTEMPTS', 10),

    'retry_interval_hours' => (int) env('PAYPLUS_RETRY_INTERVAL_HOURS', 24),

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

    /*
    | The card-update re-vault page (CardUpdateService). `charge_method` 0 is
    | PayPlus's authorize/verify-only mode — a symbolic amount is validated but
    | NOT captured, and the page vaults the token (create_token). UNVERIFIED on
    | a live terminal: if verify-only turns out not to vault, set
    | PAYPLUS_CARD_UPDATE_CHARGE_METHOD=1 (capture the symbolic 1 ₪) and refund
    | by store policy. Both are env flips, not code changes.
    */
    'card_update_amount' => (float) env('PAYPLUS_CARD_UPDATE_AMOUNT', 1.0),
    'card_update_charge_method' => (int) env('PAYPLUS_CARD_UPDATE_CHARGE_METHOD', 0),
];
