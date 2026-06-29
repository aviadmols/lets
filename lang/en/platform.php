<?php

// Platform-admin (Shops/Accounts + Enter/Exit context) strings (W2).
// English is the default; mirror EVERY key in lang/he/platform.php.
return [
    // Shops / Accounts list
    'shops' => [
        'nav' => 'Shops',
        'title' => 'Shops',
        'model' => 'Shop',
        'view' => 'View',
        'empty' => 'No shops have installed the app yet.',
        'col' => [
            'domain' => 'Store domain',
            'name' => 'Name',
            'platform' => 'Platform',
            'status' => 'Status',
            'plan' => 'Plan',
            'payplus' => 'PayPlus',
            'products' => 'Products',
            'active_subs' => 'Active subs',
            'revenue' => 'Processed revenue',
            'installed_at' => 'Installed',
            'uninstalled_at' => 'Uninstalled',
        ],
    ],

    // Shop status labels (Shop model statuses — distinct from plan/ledger statuses)
    'status' => [
        'installed' => 'Installed',
        'active' => 'Active',
        'uninstalled' => 'Uninstalled',
    ],

    // Catalog platform labels
    'platform' => [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ],

    // Enter / Exit shop context switch
    'enter' => [
        'action' => 'Enter shop',
        'entered' => 'Now viewing :shop. All actions are recorded as platform admin.',
    ],
    'exit' => [
        'action' => 'Exit',
        'exited' => 'Returned to platform mode.',
    ],

    // The persistent "Viewing as {shop}" banner
    'banner' => [
        'viewing_as' => 'Viewing as :shop',
        'note' => 'Acting on the merchant’s behalf — every change is logged.',
    ],

    // Read-only account overview (ViewShop)
    'overview' => [
        'account' => 'Account',
        'products' => 'Products',
        'active_subscriptions' => 'Active subscriptions',
        'revenue' => 'Processed revenue',
        'recent_activity' => 'Recent activity',
        'payplus' => 'PayPlus connection',
        'shopify' => 'Shopify connection',
        'connected' => 'Connected',
        'not_connected' => 'Not connected',
    ],

    // Admin-driven WooCommerce onboarding (W11)
    'woo' => [
        'add' => 'Add WooCommerce store',
        'add_heading' => 'Connect a WooCommerce store',
        'add_intro' => 'Enter the store domain. We generate a connection token + a plugin link to install on the store.',
        'domain' => 'Store domain',
        'domain_help' => 'The WooCommerce site domain, e.g. store.example.com',
        'name' => 'Store name (optional)',
        'email' => 'Merchant login email (optional)',
        'email_help' => 'If set, creates a dashboard login the merchant claims via password reset.',
        'generate' => 'Generate connection token',
        'created' => 'WooCommerce store :shop created. Copy the connection token below.',
        'connection' => 'Connection details',
        'connection_intro' => 'Paste this token into the LETS plugin on :domain to connect the store.',
        'token_label' => 'Connection token',
        'token_once' => 'This token is shown once. If you lose it, regenerate it from the shop’s page.',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'download' => 'Download the WordPress plugin',
        'step_download' => 'Download the LETS plugin and install it on the WooCommerce store.',
        'step_install' => 'Activate the plugin, then open Settings → LETS.',
        'step_paste' => 'Paste the connection token and click Connect.',
        'done' => 'Done',
        // Shop detail page (W11 addendum): persistent connect tooling + WP status.
        'section_title' => 'WooCommerce connection',
        'connection_status' => 'WordPress connection',
        'token_action' => 'Connection token',
        'token_regen_warning' => 'A fresh connection token is generated and any previous one stops working — the merchant must paste the new token into the plugin.',
        'detail_hint' => 'Use “Connection token” above to issue the token to paste into the WordPress plugin, or “Download plugin” to get the installer.',
    ],

    // Top-bar shop switcher (W12): the platform admin sees/changes the entered shop.
    'switcher' => [
        'select' => 'Select a shop',
        'heading' => 'Switch shop',
        'view_all' => 'View all shops →',
        'exit' => 'Exit to platform',
    ],
];
