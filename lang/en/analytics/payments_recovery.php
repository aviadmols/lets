<?php

// Analytics › Payments › Recovery. Read as __('analytics/payments_recovery.key').
// Mirror in lang/he/analytics/payments_recovery.php.
return [
    'title' => 'Payment recovery',

    'empty' => [
        'title' => 'No failed payments in this period',
        'body' => 'Recovery appears once a charge fails and the retry ladder or a card update takes over.',
    ],

    'strategy' => [
        'title' => 'Recovery strategies performance',
        'subtitle' => 'What each strategy handled and brought back, in the selected period',
        'retry_ladder' => 'Retry ladder (:count attempts)',
        'card_update' => 'Card-update link',
        'backup_card' => 'Backup card',
        'active' => 'Active',
        'note' => 'A payment can be handled by both strategies; it counts as recovered by the card update when the card was replaced before it paid.',
        'col' => [
            'strategy' => 'Strategy',
            'status' => 'Status',
            'failed' => 'Payment failed',
            'under' => 'Under recovery',
            'recovered' => 'Recovered',
            'rate' => 'Recovery rate',
        ],
    ],

    'via' => [
        'retry' => 'Via retry',
        'card_update' => 'Via card update',
    ],

    'split' => [
        'title' => 'Recovery contribution',
        'subtitle' => 'Recovered via retry vs via card update, and by retry number',
        'caption' => 'via retry',
        'note' => ':count payments failed in the selected period and were recovered.',
    ],

    'retry' => [
        'title' => 'Recovery by retry number',
        'retry' => 'Retry number',
        'number' => 'Retry #:n',
        'attempts' => 'Attempts',
        'recovered' => 'Recovered',
        'rate' => 'Recovery rate',
        'retry_path' => 'Via retry',
        'card_update' => 'Via card update',
    ],

    'trend' => [
        'title' => 'Recovery contribution trend',
        'subtitle' => 'Payments recovered, by the day they were paid',
    ],

    'reason' => [
        'title' => 'Failure-reason-wise recovery',
        'subtitle' => 'Which declines come back, and through which path',
        'reason' => 'Reason',
        'attempts' => 'Failed payments',
        'recovered' => 'Recovered',
        'under' => 'Under recovery',
        'rate' => 'Recovery rate',
        'retry' => 'Via retry',
        'card_update' => 'Via card update',
    ],
];
