<?php

// Sidebar navigation labels (docs/ux/01-navigation.md). Mirror every key in lang/he/nav.php.
return [
    // top-level + leaves
    'home' => 'Home',
    'analytics' => 'Analytics',
    'customers' => 'Customers',
    'subscriptions' => 'Subscriptions',
    'orders' => 'Orders',
    'order_errors' => 'Order Errors',
    'products' => 'Products',
    'discounts' => 'Discounts',
    'cross_sell_upsell' => 'Cross-Sell & Upsell',
    'post_purchase_offers' => 'Post-Purchase Offers',
    'storefront' => 'Storefront',
    'settings' => 'Settings',
    'payments' => 'Payments',
    'invoices' => 'Invoices',
    'shopify_subscriptions' => 'Shopify Subscriptions',

    // new placeholder / extra leaves
    'segments' => 'Segments',
    'credits' => 'Credits',
    'loyalty' => 'Loyalty',
    'churn_tools' => 'Churn tools',
    'sms' => 'SMS',
    'email' => 'Email',
    'tools_apps' => 'Tools & apps',

    // group headers
    'group' => [
        'platform' => 'Platform',
        'customers' => 'Customers',
        'products' => 'Products',
        'payments' => 'Payments',
        'upsell' => 'Cross-Sell & Upsell',
        'settings' => 'Settings',
    ],

    // store widget
    'store_widget' => [
        'upgrade' => 'Upgrade',
        'switch_shop' => 'Switch store',
    ],
    'support' => [
        'chat' => 'Chat support',
    ],
    // the store card at the foot of the sidebar
    'platform_name' => [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ],

    // the top bar (approved sketch): search, bell, help, language pill
    'search' => [
        'label' => 'Search',
        'placeholder' => 'Search customers, subscriptions, orders…',
        'group' => [
            'customers' => 'Customers',
            'subscriptions' => 'Subscriptions',
            'orders' => 'Orders',
        ],
        'order' => 'Order #:number',
    ],
    'alerts' => [
        'label' => 'Notifications',
        'label_pending' => 'Notifications — items need attention',
        'heading' => 'Needs attention',
        'failed_charges' => 'Charges that could not be collected',
        'invoices' => 'Invoices that need attention',
        'empty' => 'Nothing needs your attention.',
    ],
    'help' => 'Help — email LETS support',
    'language' => 'Language',
    'locale_short' => [
        'en' => 'EN',
        'he' => 'עב',
    ],
];
