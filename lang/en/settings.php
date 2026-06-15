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
    ],

    'validation' => [
        'required_field' => 'This field is required.',
    ],
];
