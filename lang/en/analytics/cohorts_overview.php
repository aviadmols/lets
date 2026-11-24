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
    'contracts_note' => 'Shopify-Payments subscriptions start counting at their first renewal: their checkout order is Shopify\'s and never reaches Let’s Subscribe.',

    'churn' => [
        'insight' => [
            'title' => 'Most people leave in month :n',
            'body' => ':left of :base (:pct) left in month :n. All other months together: :rest.',
        ],
        'kpi' => [
            'after' => '{1} Still active after 1 month|[2,*] Still active after :count months',
            'left_in' => ':count left in month :n',
            'gone' => 'Left to date',
            'gone_note' => ':pct of :joined :people who joined',
        ],
        'drops' => [
            'title' => 'In which month they leave',
            'subtitle' => 'Who left, by how long they had been members · all cohorts together',
            'month' => 'Month :n',
            'value' => ':count left · :pct',
            'note' => 'The percent is of those still active at the start of that month.',
        ],
        'curve' => [
            'title' => 'Retention curve',
            'subtitle' => 'Share still active, month by month · all cohorts together',
            'series' => 'Still active',
            'note' => 'Each month counts every cohort that has reached it, weighted by its people. The curve stops at a month fewer than 20 people have reached.',
        ],
        'table' => [
            'title' => 'Cohort table',
            'subtitle' => 'Each row is the month they joined. Each cell: who is still active, and below it how many left that month.',
            'joined' => 'Joined in',
            'churned' => 'Left to date',
            'left' => '{1} 1 left|[2,*] :count left',
            'none_left' => 'Nobody left',
            'small' => 'Small sample',
            'current' => 'This month',
            'hide_empty' => 'Hide months nobody joined',
            'show_empty' => 'Show every month',
            'legend' => [
                'high' => '90%+ still active',
                'mid' => '60–90%',
                'low' => 'Under 60%',
                'small' => 'Small sample (under 20)',
                'empty' => 'Empty cell: a month not reached yet',
            ],
        ],
    ],
    'empty' => [
        'title' => 'No one joined in this window',
        'body' => 'Cohorts appear once subscriptions start inside the selected months. Try a longer window.',
    ],
];
