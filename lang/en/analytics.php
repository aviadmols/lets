<?php

// Analytics page (subscription KPIs + subscribers trend). Mirror in lang/he/analytics.php.
return [
    'title' => 'Analytics',

    'kpi' => [
        'active_subscribers' => 'Active subscribers',
        'active_subscriptions' => 'Active subscriptions',
        'products_quantity' => 'Subscribed products quantity',
        'mrr' => 'Active MRR',
    ],

    'trend' => [
        'title' => 'Subscribers trend',
        'period' => 'Period',
        'range' => [
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
            'quarter' => 'Last 90 days',
        ],
        'empty' => 'No subscription activity in this period yet.',
        'note' => 'The line is active subscriptions per day (both billing rails); bars are new signups and cancellations. MRR normalises every cycle to a month.',
    ],

    'legend' => [
        'active' => 'Active subscriptions',
        'new' => 'New',
        'cancelled' => 'Cancelled',
    ],

    'chart' => [
        'active_tip' => ':count active',
        'new_tip' => ':count new',
        'cancelled_tip' => ':count cancelled',
    ],
];
