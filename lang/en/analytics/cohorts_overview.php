<?php

// Analytics › Cohorts. Read as __('analytics/cohorts_overview.key').
// Mirror in lang/he/analytics/cohorts_overview.php.
return [
    'title' => ':metric by joining month',
    'subtitle' => 'Each row is the month a cohort joined; each column is months since joining.',
    'month' => 'Month :n',
    'mode_label' => 'Show as percent or number',

    'level' => [
        'subscriber' => 'Subscriber',
        'subscription' => 'Subscription',
    ],

    'size' => [
        'subscriber' => 'Subscribers',
        'subscription' => 'Subscriptions',
    ],

    'metric' => [
        'label' => 'Metric: :metric',
        'aria' => 'Cohort metric',
        'retention' => [
            'subscriber' => 'Subscriber retention',
            'subscription' => 'Subscription retention',
        ],
        'orders' => [
            'subscriber' => 'Orders placed (subscribers)',
            'subscription' => 'Orders placed (subscriptions)',
        ],
        'avg_orders' => [
            'subscriber' => 'Avg cumulative orders per subscriber',
            'subscription' => 'Avg cumulative orders per subscription',
        ],
        'revenue' => [
            'subscriber' => 'Revenue realized (subscribers)',
            'subscription' => 'Revenue realized (subscriptions)',
        ],
        'ltv' => [
            'subscriber' => 'Avg subscriber LTV',
            'subscription' => 'Avg subscription LTV',
        ],
    ],

    'span' => [
        'label' => 'Cohort window',
        'months' => 'Last :count months',
        'ytd' => 'Year to date',
    ],

    'formula' => [
        'retention' => 'Retention at month m = members still active m months after they joined ÷ cohort size. The newest month of each row is read today.',
        'orders' => 'Paid orders in each month since joining (refunds netted out). % compares each month with the cohort\'s Month 0.',
        'avg_orders' => 'Paid orders from joining through month m ÷ cohort size.',
        'revenue' => 'Money received in each month since joining, net of refunds. % compares each month with the cohort\'s Month 0.',
        'ltv' => 'Money received from joining through month m, net of refunds ÷ cohort size.',
    ],

    'cumulative_note' => 'Averages grow month by month, so they show as values; the shading compares every cell with the largest one.',
    'contracts_note' => 'Shopify-Payments subscriptions start counting at their first renewal: their checkout order is Shopify\'s and never reaches LETS.',

    'empty' => [
        'title' => 'No one joined in this window',
        'body' => 'Cohorts appear once subscriptions start inside the selected months. Try a longer window.',
    ],
];
