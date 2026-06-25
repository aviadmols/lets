<?php

// Observability dashboard strings (docs/ux/10 §observability, ARCHITECTURE.md §6.6).
// Mirror EVERY key in lang/he/observability.php.
return [
    'title' => 'Observability',
    'nav' => 'Observability',

    'scope' => [
        'platform' => 'All shops (platform aggregate)',
        'shop' => 'This shop',
    ],

    'window' => [
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
    ],

    'kpi' => [
        'success_rate' => 'Charge-success rate',
        'failed' => 'Failed charges',
        'refunded' => 'Refunds',
        'total_charged' => 'Total charged',
    ],

    'health' => [
        'title' => 'Queues & scheduler',
    ],

    'scheduler' => [
        'label' => 'Due-charge scheduler',
        'ago' => '(:minutes min ago)',
        'never' => 'No run recorded yet',
    ],

    'plans' => [
        'title' => 'Plans by status',
    ],

    'failures' => [
        'title' => 'Needs attention — recent failed charges',
        'empty' => 'No failed charges',
        'empty_body' => 'Every recent charge attempt succeeded.',
        'no_reason' => 'No reason recorded',
        'col' => [
            'shop' => 'Shop',
            'context' => 'Context',
            'amount' => 'Amount',
            'reason' => 'Reason',
            'when' => 'When',
        ],
    ],
];
