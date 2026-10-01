<?php

// Analytics › Subscribers › Order funnel. Read as __('analytics/subscribers_order_funnel.key').
// Mirror in lang/he/analytics/subscribers_order_funnel.php.
return [
    'order_wise' => [
        'title' => 'Order-wise active subscriptions',
        'subtitle' => ':n active subscription by number of completed orders|:n active subscriptions by number of completed orders',
        'series' => 'Active subscriptions',
        'loyal' => ':n or more orders',
        'note' => 'Completed orders per subscription, as of today · 12 or more orders: :n subscriptions. A Shopify subscription counts its checkout order plus every successful renewal.',
    ],

    'mode' => [
        'all' => 'All orders',
        'dunning' => 'Dunning',
        'non_dunning' => 'Non-dunning',
    ],

    'unit' => [
        'count' => '#',
        'percent' => '%',
    ],

    'funnel' => [
        'title' => 'Subscription order funnel',
        'stage_1' => 'Stage 1 · scheduled → attempted',
        'stage_1_not_tracked' => 'A due charge that was never attempted leaves no record, so scheduled, rescheduled, skipped and paused-or-cancelled-before-the-order are not tracked yet.',
        'stage_2' => 'Stage 2 · attempted → succeeded',
        'attempted' => 'Attempted',
        'leak' => ':label (:share)',
        'note' => 'Percentages are of attempted orders in the period. Dunning = orders that failed at least once and entered the retry cycle.',
    ],

    'outcome' => [
        'success' => 'Succeeded',
        'retrying' => 'Failed, to be retried',
        'failed' => 'Failed, no retry left',
        'pending' => 'In progress',
    ],

    'leakage' => [
        'title' => 'Order-number-wise leakage',
        'subtitle' => 'Outcome of attempted orders, by the subscription\'s order number',
        'order' => 'Order number',
        'all' => 'All orders',
        'note' => 'Scheduled, rescheduled, skipped, paused and cancelled columns are not tracked yet — LETS records an order only once it is attempted. Percentages are of each row\'s attempted orders.',
    ],
];
