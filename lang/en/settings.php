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
        'reason' => [
            'auth' => 'invalid API key or secret',
            'transport' => 'network or server error',
            'malformed' => 'unexpected response',
        ],
    ],

    'validation' => [
        'required_field' => 'This field is required.',
    ],
];
