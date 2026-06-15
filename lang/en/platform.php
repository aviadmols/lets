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
];
