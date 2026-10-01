<?php

// Analytics › Subscribers › Revenue. Read as __('analytics/subscribers_revenue.key').
// Mirror in lang/he/analytics/subscribers_revenue.php.
return [
    'customers' => [
        'title' => 'Subscriber vs non-subscriber revenue',
        'subtitle' => 'Revenue from all orders of subscriber customers vs customers who never subscribed',
        'not_tracked' => 'This needs every order the store takes, including customers who never subscribed. LETS records only the charges it bills, so it shows nothing rather than a guess.',
    ],

    'orders' => [
        'title' => 'Subscription vs non-subscription revenue',
        'subtitle' => 'Split by order type — subscription orders vs one-time orders',
        'overall' => 'Overall revenue',
        'subscription' => 'Subscription revenue',
        'share' => 'Subscription revenue share',
        'note' => 'Overall revenue and the share need the store\'s one-time orders, which LETS does not ingest yet. Subscription revenue is real — see the split below.',
    ],

    'split' => [
        'title' => 'Recurring vs checkout subscription revenue',
        'subtitle' => 'Scheduled recurring charges and the checkout orders that created subscriptions',
        'total' => 'Total subscription revenue',
        'recurring' => 'Recurring revenue · :share',
        'checkout' => 'Checkout subscription revenue · :share',
        'note' => 'Net of refunds. Checkout = a subscription\'s first charge in LETS. Shopify-Payments renewals count as recurring; their checkout order lives in Shopify.',
    ],

    'series' => [
        'recurring' => 'Recurring orders',
        'checkout' => 'Checkout orders',
    ],

    'chart' => [
        'orders' => 'Orders placed',
        'revenue' => 'Revenue generated',
        'aov' => 'AOV',
        'vs' => ':a vs :b',
    ],
];
