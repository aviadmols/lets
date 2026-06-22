<?php

// Subscriptions list + detail (docs/ux/30-subscriptions.md). Mirror in lang/he/subscriptions.php.
return [
    'list' => [
        'title' => 'Subscriptions',
        'search_placeholder' => 'Search customer',
        'col' => [
            'customer' => 'Customer',
            'kind' => 'Kind',
            'status' => 'Status',
            'next_charge' => 'Next charge',
            'amount_balance' => 'Amount / Balance',
        ],
        'empty' => [
            'first_run' => 'No subscriptions yet. Create a plan on a product or take a deposit checkout to start.',
        ],
    ],

    'filter' => [
        'kind' => [
            'all' => 'All',
            'installments' => 'Installments',
            'recurring' => 'Recurring',
        ],
    ],

    'detail' => [
        'remaining_of_total' => 'Remaining :balance of :total',
        'every_frequency' => 'Every :frequency',
        'plan_items' => 'Plan items',
        'billing_schedule' => 'Billing schedule',
        'deposit' => 'Deposit',
        'installment_n' => 'Installment :n',
        'n_of_m' => ':n of :m',
        'fulfillment_locked' => 'Fulfillment locked until fully paid',
        'order_released' => 'Order released for fulfillment',
        'payment_schedule' => 'Payment schedule',
        'schedule_empty' => 'No scheduled payments yet.',
        'payment_ledger' => 'Payment ledger',
        'ledger_empty' => 'No charges yet.',
        'timeline' => 'Timeline',
        'next_cycle' => 'Next cycle',
        'started' => 'Started',
        'col' => [
            'date' => 'Date',
            'context' => 'Context',
            'amount' => 'Amount',
            'status' => 'Status',
            'tx' => 'Transaction',
            'sequence' => 'Item',
            'scheduled_for' => 'Scheduled for',
            'attempts' => 'Attempts',
            'charged_at' => 'Charged at',
            'note' => 'Note',
        ],
        'note' => [
            'paid' => 'Paid',
            'refunded' => 'Refunded',
            'attempt_error' => 'Attempt :attempt — :error',
            'attempt_failed' => 'Attempt :attempt failed',
            'retry_on' => 'Retry scheduled for :date',
            'retry_pending' => 'Retry scheduled',
            'awaiting_customer' => 'Awaiting customer',
            'scheduled' => 'Scheduled',
        ],
    ],
];
