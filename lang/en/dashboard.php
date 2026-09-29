<?php

// Home KPI dashboard strings (docs/ux/10-home-dashboard.md). Mirror in lang/he/dashboard.php.
return [
    'title' => 'Home',

    'kpi' => [
        'processed_revenue' => 'Processed revenue',
        'active_subscribers' => 'Active subscribers',
        'new_subscribers' => 'New subscribers',
        'churned_subscribers' => 'Churned subscribers',
        // beside the delta chip; :days is the selected range
        'compare' => 'vs previous day|vs previous :days days',
    ],

    // the amber strip: the two queues the engine leaves to a human
    'attention' => [
        'charges' => ':count charge could not be collected|:count charges could not be collected',
        'invoices' => ':count invoice needs attention|:count invoices need attention',
        'and' => 'and',
        'review_charges' => 'Review failed charges',
        'open_invoices' => 'Open invoices',
    ],

    'performance' => [
        'title' => 'Performance at a glance',
        'period' => 'Period',
        'range' => [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
        ],
        'this_period' => 'This period',
        'prev_period' => 'Previous period',
        'metric_col' => 'Metric',
        'change' => 'Change',
        // a rate's move, in percentage points
        'pts' => ':value pts',
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

    /*
    | Subscribers whose cycle could not be collected. They are held on the date
    | they owe rather than rolled to next month, so they bill nobody until
    | somebody settles them or the customer updates their card.
    */
    'unpaid' => [
        'title' => 'Could not be charged',
        'help' => 'We tried once a day for a week and the payment did not go through. These subscriptions are on hold — they will not be billed again on their own. Open one to charge it, or send the customer a link to update their card.',
        'customer' => 'Customer',
        'amount' => 'Amount',
        'due' => 'Cycle owed',
        'since' => 'On hold',
    ],

    'upcoming' => [
        'title' => 'Upcoming orders',
        'customer' => 'Customer',
        'type' => 'Type',
        'amount' => 'Amount',
        'date' => 'Next charge',
        'empty' => 'No upcoming charges scheduled.',
        'view_all' => 'View all subscriptions →',
    ],

    'empty' => [
        'first_run' => [
            'title' => "Let's process your first payment",
            'body' => 'Connect your PayPlus account and create a plan to see live numbers here.',
            'cta' => 'Connect PayPlus',
        ],
    ],
];
