<?php

// Analytics › Payments › Failures. Read as __('analytics/payments_failures.key').
// Mirror in lang/he/analytics/payments_failures.php. The reason labels are shared
// by Payments › Recovery (DeclineReason::label()).
return [
    'total' => [
        'title' => 'Total failures',
        'label' => 'Failed first attempts',
        'share' => ':rate of :attempts first attempts',
        'entered' => 'Entered recovery',
        'recovered' => 'Recovered',
        'under' => 'Still under recovery',
        'lost' => 'Lost',
    ],

    'sources' => [
        'title' => 'Failures by payment source',
        'caption' => 'by source',
    ],

    'reasons' => [
        'title' => 'Top failure reasons',
        'caption' => 'by reason',
    ],

    'reason_note' => 'PayPlus returns the same code for every decline, so reasons are read from its own wording; anything not recognised is counted as Other.',

    'over_time' => [
        'title' => 'Failure reasons over time',
        'subtitle' => 'Failed first attempts per bucket, stacked by reason',
    ],

    'reason' => [
        'insufficient_funds' => 'Insufficient funds',
        'blocked' => 'Card blocked',
        'expired' => 'Expired or invalid card',
        'stolen_lost' => 'Reported stolen or lost',
        'refused' => 'Refused by the issuer',
        'call_issuer' => 'Call the issuer',
        'token_missing' => 'Saved card not found',
        'technical' => 'Technical error',
        'other' => 'Other',
    ],

    'export' => [
        'reason' => 'Reason',
        'failures' => 'Failed first attempts',
        'share' => 'Share %',
    ],
];
