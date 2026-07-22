<?php

// Settings (docs/ux/50-settings.md). Mirror in lang/he/settings.php.
return [
    'title' => 'Settings',

    'section' => [
        'payplus' => 'PayPlus Connection',
        'shopify' => 'Shopify',
        'payment' => 'Payment',
        'shipping' => 'Shipping',
        'legal' => 'Legal',
        'order_processing' => 'Order processing',
        'merchant_billing' => 'Merchant billing',
        'mail' => 'Mail settings',
        'plan_billing' => 'Plan & billing',
        'notifications' => 'Notifications',
    ],

    'payplus' => [
        'heading' => 'PayPlus Connection',
        'intro' => 'Enter your own PayPlus account credentials. They are stored encrypted and used only for this store.',
        'api_key' => 'API key',
        'secret_key' => 'Secret key',
        'terminal_uid' => 'Terminal UID',
        'cashier_uid' => 'Cashier UID',
        'payment_page_uid' => 'Payment page UID',
        'base_url' => 'API base URL',
        'webhook_secret' => 'Webhook secret',
        'masked_hint' => 'Saved. Paste a new value to replace it.',
        'test' => 'Test connection',
        'test_ok' => 'Connection successful.',
        'test_fail' => 'Connection failed: :reason',
        'save' => 'Save credentials',
        'saved' => 'PayPlus credentials saved.',
        'status' => [
            'connected' => 'Connected',
            'not_connected' => 'Not connected',
            'error' => 'Connection error',
        ],
        'empty' => 'Connect your PayPlus account to start charging.',

        // Environment toggle.
        'environment' => 'Environment',
        'env_production' => 'Production',
        'env_sandbox' => 'Sandbox',

        // Connect + auto-discovery flow.
        'connect' => 'Connect',
        'connect_need_creds' => 'Enter your API key and Secret key first.',
        'connect_found' => 'Found :count terminal(s) on your PayPlus account.',
        'connect_failed' => 'Could not reach PayPlus: :reason',
        'pages_failed' => 'Could not load payment pages: :reason',
        'discovery_heading' => 'Terminal & payment page',
        'discovery_intro' => 'Discovered from your PayPlus account. Pick one if you have several.',
        'terminal' => 'Terminal',
        'terminal_inactive' => 'inactive',
        'payment_page' => 'Payment page',
        'connected_label' => 'Connected to',
        'advanced' => 'Advanced',
        'advanced_intro' => 'Optional. The webhook secret is not discoverable via the API — paste it only if PayPlus gave you one.',
        // A payment page is REQUIRED — PayPlus cannot create a card page without it.
        'needs_payment_page' => 'Choose a payment page before saving.',
        'needs_payment_page_help' => 'PayPlus cannot create a credit-card page without a payment page, so checkout would fail. If no payment pages are listed, create one in your PayPlus dashboard under this terminal, then click “Reload payment pages”.',
        'pages_failed_help' => 'Your existing payment page (if any) was kept. Fix the cause above and reload — nothing was changed.',
        'rediscover' => 'Reload payment pages',
        'reason' => [
            'auth' => 'invalid API key or secret',
            'transport' => 'network or server error',
            'malformed' => 'unexpected response',
            'empty' => 'PayPlus returned no payment pages for this terminal — create one in your PayPlus dashboard',
        ],
    ],

    // Settings → Invoicing (Green Invoice / Morning). Admin-facing copy only — the
    // text that lands on the DOCUMENTS themselves lives in the invoicing.php files.
    'invoicing' => [
        'title' => 'Invoicing',
        'empty' => 'Connect your Green Invoice account to issue documents automatically.',
        'save' => 'Save invoicing settings',
        'saved' => 'Invoicing settings saved.',
        'test' => 'Test connection',
        'test_ok' => 'Connected to Green Invoice.',
        'test_fail' => 'Connection failed: :reason',
        'masked_hint' => 'Saved. Paste a new value to replace it.',

        'status' => [
            'connected' => 'Connected',
            'not_connected' => 'Not connected',
            'error' => 'Connection error',
        ],

        'reason' => [
            'no_credentials' => 'no API key or secret saved',
            'unauthorized' => 'Green Invoice rejected these credentials',
            'rejected' => 'Green Invoice rejected the request',
            'transport' => 'network or server error',
            'token_transport' => 'could not reach Green Invoice to sign in',
        ],

        // 1 — connection
        'connection_heading' => 'Green Invoice connection',
        'connection_intro' => 'Enter your own Green Invoice API key. It is stored encrypted and used only for this store. Create one in Green Invoice under Settings → Developer tools → API keys.',
        'api_key_id' => 'API key ID',
        'api_secret' => 'API secret',
        'environment' => 'Environment',
        'env_production' => 'Production',
        'env_sandbox' => 'Sandbox',
        'needs_credentials' => 'Add your Green Invoice API key before turning invoicing on.',
        'needs_credentials_help' => 'Without credentials every document would fail. Your other settings were saved; invoicing stays off until a key is present.',

        // 2 — scope
        'scope_heading' => 'What gets invoiced',
        'scope_intro' => 'Choose which orders produce an accounting document.',
        'enabled' => 'Issue documents automatically',
        'enabled_help' => 'When off, nothing is sent to Green Invoice and no documents are created.',
        'scope' => 'Scope',
        'scope_plans_only' => 'Subscription and installment orders only',
        'scope_plans_only_help' => 'Documents for deposits, installments, subscription renewals, upsells and refunds handled by LETS.',
        'scope_all_orders' => 'Every order on the site',
        'scope_all_orders_help' => 'Also issue a document for plain store orders LETS never handled — paid by bank transfer, cash on delivery, or any other method.',
        'scope_all_orders_shopify' => 'Invoicing every store order is available on WooCommerce stores. On Shopify, documents are issued for subscription and installment orders.',
        'trigger_statuses' => 'Issue when the order reaches',
        'trigger_statuses_help' => 'Pick the order statuses that mean you have the money. An order that later moves between these statuses is still invoiced only once.',
        // WooCommerce ORDER statuses. Deliberately not `status.*` — that key holds the
        // provider CONNECTION state, and one group cannot mean two things.
        'order_status' => [
            'processing' => 'Processing',
            'completed' => 'Completed',
            'on-hold' => 'On hold',
        ],

        // 3 — document types
        'doc_types_heading' => 'Document types',
        'doc_types_intro' => 'Which Green Invoice document to issue for each kind of payment. The defaults suit most businesses — change them if your accountant asks, or if you are an exempt dealer (osek patur) who cannot issue tax invoices.',
        'context' => [
            'deposit' => 'Deposit paid',
            'installment' => 'Installment paid',
            'final_installment' => 'Final installment (plan fully paid)',
            'recurring' => 'Subscription renewal',
            'upsell' => 'Post-purchase upsell',
            'platform_order' => 'Plain store order',
            'refund' => 'Refund',
            'cancellation' => 'Cancellation',
        ],
        'doc_type' => [
            300 => 'Transaction invoice (חשבונית עסקה)',
            305 => 'Tax invoice (חשבונית מס)',
            320 => 'Tax invoice / receipt (חשבונית מס וקבלה)',
            330 => 'Credit note (חשבונית זיכוי)',
            400 => 'Receipt (קבלה)',
            405 => 'Donation receipt (קבלת תרומה)',
        ],

        // 4 — options
        'options_heading' => 'Delivery and format',
        'options_intro' => 'How the document reaches your customer and how it is written.',
        'send_email' => 'Email the document to the customer',
        'send_email_help' => 'Green Invoice sends the document to the customer’s email address. When off, the document is created silently and only you see it.',
        'attach_to_order' => 'Show the document on the store order',
        'attach_to_order_help' => 'Adds the document number and link to the order in your store, so you can open it without leaving the order screen.',
        'document_language' => 'Document language',
        'lang_he' => 'Hebrew',
        'lang_en' => 'English',
        'vat_type' => 'VAT type',
        'vat_type_help' => 'The Green Invoice VAT type code for your business. Leave at 0 unless your accountant tells you otherwise.',
        'rounding' => 'Round document totals',
        'rounding_help' => 'Rounds the document total to the nearest whole shekel, as Green Invoice does for cash sales.',
    ],

    'validation' => [
        'required_field' => 'This field is required.',
    ],
];
