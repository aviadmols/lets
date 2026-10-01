<?php

// Analytics › Cancellations › Saves. Read as __('analytics/cancellations_saves.key').
// Mirror in lang/he/analytics/cancellations_saves.php.
return [
    'kpi' => [
        'save_rate' => 'Save rate',
        'saved_mrr' => 'Saved MRR',
        'reactivated' => 'Back after a failed payment',
        'returned' => 'Came back after cancelling',
    ],

    'caption' => [
        'mrr_back' => ':mrr MRR back',
        'vs_cancelled' => 'new subscriptions from past cancellers · :n cancelled in the period',
    ],

    'flow' => [
        'title' => 'Cancellation flow',
        'subtitle' => 'Benefits page · reason-based treatments · retention offers · instant winback offers',
        'empty_title' => 'There is no cancellation flow to measure yet',
        'empty_body' => 'In LETS a cancellation happens in one step — from the account area, the customer portal or the admin — with no benefits page, reason question or retention offer in between. So no cancellation attempt, save, saved MRR or additional revenue is ever recorded, and this screen shows none rather than a guess. It needs a cancellation flow that records each attempt, the reason, the tool shown and the outcome.',
    ],

    'comebacks' => [
        'title' => 'Subscriptions that came back',
        'subtitle' => 'What LETS does record: recovered after a failed payment, or a new subscription from someone who had cancelled',
        'note' => 'Recovered = a failed or unpaid subscription that became active again. Came back = a person who cancelled earlier and subscribed again (a cancelled subscription cannot be revived, so a comeback is a new one).',
    ],

    'type' => [
        'reactivated' => 'Recovered after failed payment',
        'returned' => 'Came back after cancelling',
    ],

    'table' => [
        'title' => 'Latest comebacks',
        'date' => 'Date',
        'subscription' => 'Subscription',
        'customer' => 'Customer',
        'type' => 'How',
        'mrr' => 'MRR',
    ],

    'tools' => [
        'reasons' => 'Reason-wise saves',
        'offers' => 'Retention offers',
        'winback' => 'Instant winback offers',
        'needs' => 'Needs a cancellation flow that records the reason, the offer shown and whether the customer stayed.',
    ],
];
