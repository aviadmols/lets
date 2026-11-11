<?php

// Analytics › Summary — the plain screen. One unit throughout: a subscription.
// Mirror of lang/he/analytics/summary_overview.php.
return [
    'period' => [
        'label' => 'Period',
        'show' => 'Show',
        'showing' => 'Showing: :span',
    ],

    'kpi' => [
        'active' => 'Active subscriptions now',
        'mrr' => 'Monthly revenue from subscriptions',
        'joined' => 'Joined in the period',
        'left' => 'Left in the period',
    ],

    'by_type' => [
        'title' => 'Active subscriptions by type',
        'subtitle' => ':active subscriptions held by :customers customers · as of today',
        'type' => 'Subscription type',
        'active' => 'Active',
        'mrr' => 'Monthly revenue',
        'share' => 'Share',
        'unknown' => 'Unknown product',
    ],

    'trend' => [
        'active' => 'Active subscriptions',
        'active_title' => 'Active subscriptions over time',
        'active_subtitle' => 'How many subscriptions were active at each point of the period',
        'joined' => 'Joined',
        'left' => 'Left',
    ],

    'joined' => [
        'title' => 'New subscriptions',
        'subtitle' => 'How many joined each day / week / month',
        'in_period' => 'Joined in the period',
        'new' => 'New subscriptions',
        'returned' => 'Came back after leaving',
        'total' => 'Total joined',
    ],

    'lower' => [
        'title' => 'Who left, and collection problems',
    ],

    'left' => [
        'title' => 'Subscriptions that left',
        'subtitle' => 'How many left each day / week / month',
        'in_period' => 'Left in the period',
        'cancelled' => 'Cancelled',
        'expired' => 'Ended',
        'total' => 'Total left',
        'paused' => 'Paused (not counted as leaving)',
        'net' => 'Net change (joined minus left)',
        'note' => '"Ended" — a fixed-term subscription that ran out, or one whose collection stopped for good.',
    ],

    'collection' => [
        'title' => 'Subscriptions with a collection problem',
        'subtitle' => 'Right now',
        'retrying' => 'Being retried',
        'retrying_hint' => 'The charge failed and we are still retrying it',
        'stopped' => 'Collection stopped',
        'stopped_hint' => 'Retries ran out — waiting for the customer to update the card',
        'link' => 'Open failed charges →',
        'note' => 'The same subscriptions the "Failed charges" screen lists.',
    ],
];
