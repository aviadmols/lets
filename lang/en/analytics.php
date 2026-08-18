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

    /*
     * The money half, read from payment_ledger. Deliberately NOT a recovery
     * funnel: our ledger keeps one row per idempotency key and a retry re-uses
     * it, so 'recovered' cannot be proven from a column. These are the outcomes
     * the ledger can actually stand behind.
     */
    'payments' => [
        'title' => 'Payments',
        'attempted' => 'Attempted',
        'realized' => 'Collected',
        'success_rate' => 'Success rate',
        'retrying' => 'Still retrying',
        'lost' => 'Lost',
        'note' => 'Every charge opens a ledger row before the gateway is called, so an attempt that died mid-flight is counted here too. The rate is measured against settled money only.',
        'chart_title' => 'Collected vs lost (last 12 months)',
        'empty' => 'No charges yet.',
        'upcoming_title' => 'Upcoming charges',
        'upcoming_empty' => 'Nothing scheduled in this window.',
        'col_date' => 'Date',
        'col_count' => 'Subscriptions',
        'col_amount' => 'Amount',
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
