<?php

// Home KPI dashboard strings (docs/ux/10-home-dashboard.md). Mirror in lang/he/dashboard.php.
return [
    'title' => 'Home',

    'kpi' => [
        'processed_revenue' => 'Processed revenue',
        'active_subscribers' => 'Active subscribers',
        'new_subscribers' => 'New subscribers',
        'churned_subscribers' => 'Churned subscribers',
    ],

    'performance' => [
        'title' => 'Performance at a glance',
        'this_period' => 'This period',
        'prev_period' => 'Previous period',
        'metric' => [
            'mrr' => 'Monthly recurring revenue',
            'installment_balance' => 'Installment balance outstanding',
            'upsell_revenue' => 'Upsell revenue',
            'charge_success' => 'Charge-success rate',
            'failed_charges' => 'Failed charges',
        ],
    ],

    'activity' => [
        'title' => 'Recent activity',
    ],

    'upcoming' => [
        'title' => 'Upcoming orders',
        'customer' => 'Customer',
        'type' => 'Type',
        'amount' => 'Amount',
        'date' => 'Next charge',
        'empty' => 'No upcoming charges scheduled.',
    ],

    'empty' => [
        'first_run' => [
            'title' => "Let's process your first payment",
            'body' => 'Connect your PayPlus account and create a plan to see live numbers here.',
            'cta' => 'Connect PayPlus',
        ],
    ],
];
