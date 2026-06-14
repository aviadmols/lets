<?php

// Billing / subscription domain strings. Mirror every key in lang/he/billing.php.
return [
    // plan kinds
    'plan_kind' => [
        'installments' => 'Installments',
        'recurring' => 'Recurring subscription',
    ],

    // plan statuses
    'status' => [
        'draft' => 'Draft',
        'awaiting_first_payment' => 'Awaiting first payment',
        'active' => 'Active',
        'paused' => 'Paused',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    // charge contexts
    'charge_context' => [
        'deposit' => 'Deposit',
        'installment' => 'Installment',
        'recurring' => 'Recurring charge',
        'upsell' => 'Post-purchase upsell',
        'retry' => 'Retry',
        'manual' => 'Manual',
    ],

    // common labels
    'next_charge' => 'Next charge',
    'remaining_balance' => 'Remaining balance',
    'total_amount' => 'Total amount',
    'paid_amount' => 'Paid amount',
];
