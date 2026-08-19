<?php

// Customers list + detail (docs/ux/20-customers.md). Mirror in lang/he/customers.php.
return [
    'list' => [
        'title' => 'Customers',
        'search_placeholder' => 'Search name or email',
        'col' => [
            'customer' => 'Customer',
            'email' => 'Email',
            'phone' => 'Phone',
            'active_subs' => 'Active subscriptions',
            'spend' => 'Total spend',
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
        'login_as' => [
            'label' => 'Log in as customer',
            'heading' => 'Log in to the store as this customer',
            'body' => 'This browser will be signed in to the store as this customer, on their account page. Your own WordPress admin session in this browser ends — sign in again to return to it. The action is recorded on this customer’s timeline.',
            'confirm' => 'Log in as them',
            'no_email' => 'No subscription of this customer carries an email address, so the store cannot tell who they are.',
            'unavailable' => 'This store is not connected to WooCommerce.',
        ],
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

    // Contact details read live from the store and written straight back — the
    // SaaS keeps no copy, so the screen and the store cannot disagree.
    'contact' => [
        'heading' => 'Contact details',
        'edit' => 'Edit',
        'save' => 'Save to the store',
        'cancel' => 'Cancel',
        'saved' => 'Saved to your store.',
        'save_failed' => 'Your store did not accept the change.',
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
        'address' => 'Shipping address',
        'country_hint' => 'Two-letter country code, e.g. IL.',
        'reason' => [
            'empty' => 'Your store holds no contact details for this customer yet.',
            'guest' => 'This customer checked out as a guest, so there is no account in your store to edit.',
            'access_pending' => 'Shopify has not granted this app access to customer details yet. Approve the Address field under Protected customer data in the Partner Dashboard, then reconnect the app.',
            'unavailable' => 'Could not reach your store to read these details.',
        ],
    ],

    // The customer's orders, read from the store. All of them — the ones LETS
    // created are marked rather than being the only ones shown.
    'orders' => [
        'title' => 'Orders',
        'empty' => 'This customer has no orders in your store yet.',
        'from_lets' => 'LETS',
        'from_store' => 'Store',
        'col' => [
            'date' => 'Date',
            'number' => 'Order',
            'amount' => 'Amount',
            'source' => 'Created by',
        ],
    ],
];
