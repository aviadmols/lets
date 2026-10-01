<?php

// Analytics › Payments › Overview. Read as __('analytics/payments_overview.key').
// Mirror in lang/he/analytics/payments_overview.php.
return [
    'unit' => [
        'label' => 'Count or revenue',
        'count' => 'Count',
        'revenue' => 'Revenue',
    ],

    'kpi' => [
        'attempted' => 'Total attempted',
        'success' => 'Success',
        'recovered' => 'Recovered',
        'under' => 'Under recovery',
        'lost' => 'Lost',
    ],

    'monthly' => [
        'title' => 'Success vs failed revenue',
        'subtitle' => 'Last 12 months · success % above each month',
        'realized' => 'Realized',
        'under' => 'Under recovery',
        'lost' => 'Lost',
        'success' => 'Success %',
    ],

    'no_journeys' => 'No charge attempts were recorded on the Timeline in this period.',

    'first_attempt' => [
        'title' => 'First attempt metrics',
        'subtitle' => 'Charges by their first try, in the selected period',
        'rate' => 'Success rate',
        'attempted' => 'Attempted',
        'realized' => 'Realized',
    ],

    'backup' => [
        'title' => 'Backup payment metrics',
        'subtitle' => 'Backup card, charged before the recovery cycle',
        'not_tracked' => 'LETS charges one saved card per subscription — there is no backup card to try, so there is nothing to measure.',
    ],

    'cycle' => [
        'first_cycle' => 'First recovery cycle',
        'first_cycle_sub' => 'Retry #1, after the first failure',
        'subsequent_cycle' => 'Subsequent recovery cycles',
        'subsequent_cycle_sub' => 'Retry #2 onwards',
        'rate' => 'Recovery rate',
        'attempted' => 'Attempted',
        'realized' => 'Realized',
        'under' => 'Under recovery',
        'lost' => 'Lost',
        'lost_breakdown' => 'Lost breakdown',
        'lost_failed' => 'Payment failed',
        'lost_stopped' => 'Paused, cancelled or expired',
        'lost_skipped' => 'Skipped',
        'lost_line' => 'Lost :lost: payment failed :failed · paused, cancelled or expired :stopped · skipped not tracked',
    ],

    'sources' => [
        'title' => 'Payment source distribution',
        'subtitle' => 'Where each charge was taken, in the selected period',
        'source' => 'Source',
        'attempted' => 'Attempted',
        'realized' => 'Realized',
        'under' => 'Under recovery',
        'realization' => 'Realization',
        'first_attempt' => 'First attempt success',
        'first_cycle' => 'First cycle recovery',
        'subsequent_cycle' => 'Subsequent cycle recovery',
        'shopify_note' => 'Shopify Payments runs its own retries and reports only the final result, so its recovery columns stay empty.',
    ],

    'source' => [
        'payplus' => 'Saved card (PayPlus)',
        'shopify' => 'Shopify Payments',
    ],

    'country' => [
        'title' => 'Country-wise payment distribution',
    ],

    'over_time' => [
        'title' => 'Payments over time',
        'subtitle' => 'Charges by the day of their first attempt, and how each one ended',
        'first' => 'Paid at first attempt',
        'recovered' => 'Recovered',
        'under' => 'Under recovery',
        'lost' => 'Lost',
    ],
];
