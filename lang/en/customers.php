<?php

// Customers list + detail (docs/ux/20-customers.md). Mirror in lang/he/customers.php.
return [
    'list' => [
        'title' => 'Customers',
        'search_placeholder' => 'Search name or email',
        'col' => [
            'customer' => 'Customer',
            'email' => 'Email',
            'active_subs' => 'Active subscriptions',
            'payment_status' => 'Payment',
        ],
        'empty' => [
            'first_run' => 'No customers yet. They appear after your store takes its first order.',
        ],
    ],

    'detail' => [
        'kpi' => [
            'subscription_spend' => 'Subscription spend',
            'orders' => 'Orders',
            'streak' => 'Streak',
        ],
        'subscriptions_title' => 'Subscriptions',
        'shipping_address' => 'Shipping address',
        'no_subscriptions' => 'This customer has no active plans.',
        'upcoming_orders' => 'Upcoming orders',
        'recent_orders' => 'Recent orders',
        'timeline' => 'Timeline',
        'timeline_empty' => 'No activity recorded yet.',
        'panel' => [
            'overview' => 'Customer overview',
            'comm_prefs' => 'Communication preferences',
            'payment_methods' => 'Payment methods',
            'segments' => 'Segments',
            'tags' => 'Shopify tags',
            'credits' => 'Credits',
        ],
        'overview' => [
            'name' => 'Name',
            'email' => 'Email',
            'customer_id' => 'Customer ID',
            'since' => 'Customer since',
        ],
        'no_payment_methods' => 'No saved payment method.',
        'action' => [
            'open_portal' => 'Copy portal link',
            'view_in_shopify' => 'View in Shopify',
        ],
    ],
];
