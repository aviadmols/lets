<?php

// Analytics › Subscribers › Overview. Read as __('analytics/subscribers_overview.key').
// Mirror in lang/he/analytics/subscribers_overview.php.
return [
    'as_of_today' => 'Active today',

    'kpi' => [
        'subscribers' => 'Active subscribers',
        'subscriptions' => 'Active subscriptions',
        'quantity' => 'Subscribed products quantity',
        'mrr' => 'Active MRR',
    ],

    'line' => [
        'subscriber' => 'Active subscribers',
        'subscription' => 'Active subscriptions',
    ],

    'subscribers_trend' => [
        'title' => 'Subscribers trend',
        'subtitle' => 'Movements (left axis) and the active count (right axis)',
    ],

    'subscriptions_trend' => [
        'title' => 'Subscriptions trend',
        'subtitle' => 'Same series at subscription level; Cancelled replaces Churned',
    ],

    'subscribers_activity' => ['title' => 'Subscribers activity'],
    'subscriptions_activity' => ['title' => 'Subscriptions activity'],

    'activity' => [
        'active' => 'Active subscribers',
        'active_subscriptions' => 'Active subscriptions',
        'net' => 'Net active change',
        'additions' => 'Additions',
        'reductions' => 'Reductions',
    ],

    'by_frequency' => [
        'title' => 'By delivery interval',
        'caption' => 'active',
    ],

    'by_plan' => [
        'title' => 'By selling plan',
        'caption' => 'selling plan|selling plans',
    ],

    'table' => [
        'title' => 'Selling plan × frequency',
        'plan' => 'Selling plan',
        'frequency' => 'Frequency',
        'subscribers' => 'Active subscribers',
        'mrr' => 'Active MRR',
        'contribution' => 'Contribution',
    ],

    'products' => [
        'title' => 'Subscribed products activity',
        'subtitle' => 'Units added and removed across all subscriptions',
        'checkout' => 'Checkout',
        'upsells' => 'Upsells',
        'quantity_increased' => 'Quantity increased',
        'quantity_decreased' => 'Quantity decreased',
        'reactivated' => 'Reactivated',
        'resumed' => 'Resumed',
        'cancelled' => 'Cancelled',
        'paused' => 'Paused',
        'expired' => 'Expired',
        'net' => 'Net quantity change · :units subscribed units',
        'note' => 'A dash means LETS does not record that movement yet (upsells into a subscription, quantity edits).',
    ],

    'churn' => [
        'title' => 'Churn overview',
        'subtitle' => 'Subscriber-level churn this period',
        'link' => 'Cancellations →',
        'rate' => 'Churn rate',
        'lost' => 'Subscribers lost',
        'of_start' => 'of :count at period start',
        'zero_day' => '0-day churn',
        'n_of_lost' => ':n of :lost lost',
        'upcoming' => 'Upcoming-order churn',
        'cancelled_mrr' => 'Cancelled MRR',
        'cancelled_mrr_by' => 'Cancelled MRR',
        'total' => ':amount cancelled MRR this period',
    ],
];
