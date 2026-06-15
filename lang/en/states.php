<?php

// Shared state copy (loading / empty / error / partial). Mirror in lang/he/states.php.
return [
    'kpi' => [
        'no_data' => 'No data for this range',
        'error' => "Couldn't load",
    ],
    'empty' => [
        'no_results' => 'No results match your filters.',
        'clear_filters' => 'Clear filters',
    ],
    'error' => [
        'generic' => 'Something went wrong.',
        'retry' => 'Retry',
        'action_failed' => "The action couldn't be completed. No charge was made.",
    ],
    'partial' => [
        'pending_webhook' => 'Awaiting confirmation from PayPlus…',
    ],
    'gate' => [
        'locked' => 'Available on a higher plan. Upgrade to unlock.',
    ],
];
