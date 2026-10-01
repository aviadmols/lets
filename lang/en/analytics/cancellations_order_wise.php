<?php

// Analytics › Cancellations › Order-wise churn. Read as __('analytics/cancellations_order_wise.key').
// Mirror in lang/he/analytics/cancellations_order_wise.php.
return [
    'source' => [
        'label' => 'Cancellation source',
        'all' => 'All sources',
        'customer' => 'Customer',
        'admin' => 'Admin',
        'payment_failed' => 'Payment failed',
        'automatic' => 'Automatic',
    ],

    'orders_filter' => [
        'label' => 'Completed orders',
        'all' => 'Completed orders: all',
        'n' => 'Completed orders: :n',
    ],

    'by_orders' => [
        'title' => 'Churn by completed orders',
        'link' => 'Cancellations overview →',
        'summary' => ':subs subscriptions · :churned churned · :rate · :mrr MRR · :orders orders before cancellation on average',
        'orders' => 'Completed orders',
        'subscriptions' => 'Subscriptions',
        'churned' => 'Churned',
        'rate' => 'Churn rate',
        'mrr' => 'Churned MRR',
    ],

    'trend' => [
        'title' => 'Cancellation reason trend',
        'subtitle' => 'Cancelled subscriptions, one line per reason',
    ],

    'reasons' => [
        'title' => 'Churn by cancellation reason',
        'subtitle' => 'Share of the period\'s cancellations',
        'reason' => 'Cancellation reason',
        'churned' => 'Churned',
        'share' => 'Share',
        'mrr' => 'Churned MRR',
        'orders' => 'Orders before',
        'channel' => 'Most common channel',
        'note' => 'Attempts, saves, save rate, saved MRR and the top retention action need a cancellation flow, which LETS does not have yet — those columns are left out rather than shown as zeros.',
    ],
];
