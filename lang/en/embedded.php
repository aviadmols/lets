<?php

// The LETS admin as it renders INSIDE wp-admin (WooCommerce embed).
// `areas` is the sidebar-area catalogue the platform owner ticks per shop
// (App\Support\Ui\EmbeddedMenu::AREAS keys into these).
// English is the default; mirror EVERY key in lang/he/embedded.php.
return [
    'areas' => [
        'home' => 'Home',
        'customers' => 'Customers',
        'subscriptions' => 'Subscriptions',
        'loyalty' => 'Loyalty club',
        'gift_orders' => 'Gift orders',
        'import' => 'Import subscriptions',
        'products' => 'Products',
        'payments' => 'Payments',
        'documents' => 'Invoices & documents',
        'upsell' => 'Post-purchase offers',
        'storefront' => 'Storefront elements',
        'analytics' => 'Analytics',
        'observability' => 'System health',
        'settings_billing' => 'Settings · Billing',
        'settings_invoicing' => 'Settings · Invoicing',
        'settings_mail' => 'Settings · Emails',
        'settings_customer_area' => 'Settings · Customer area',
        'settings_upsell_appearance' => 'Settings · Offer appearance',
    ],

    // The 410 page shown when the one-shot sign-in link is spent or expired.
    'expired' => [
        'title' => 'This link has already been used',
        'body' => 'For security, the link that opens LETS inside WordPress works once and only for a minute.',
        'hint' => 'Go back to WordPress and click LETS again.',
    ],
];
