<?php

// Analytics › Payments › Upcoming payments. Read as __('analytics/payments_upcoming.key').
// Mirror in lang/he/analytics/payments_upcoming.php.
return [
    'window' => [
        'label' => 'Upcoming window',
        'next' => 'Next :count days',
    ],

    'kpi' => [
        'scheduled' => 'Scheduled payments',
        'amount' => 'Scheduled amount',
        'expected' => '≈ :amount expected at :rate success',
        'high' => 'High risk',
        'medium' => 'Medium risk',
        'share' => ':rate of scheduled payments',
    ],

    'table' => [
        'title' => 'Scheduled payments',
        'subtitle' => 'Every charge due in the window, with the risk that it fails',
        'note' => 'Risk: high = no usable card, a card that expires before the date, or the last charge failed; medium = the card expires that month or the last charge is still retrying. The amount is the full charge — LETS does not store delivery separately.',
    ],

    'search' => [
        'label' => 'Search scheduled payments',
        'placeholder' => 'Search by ID, name or email',
        'none_title' => 'Nothing matches',
        'none_body' => 'No scheduled payment in this window matches that search.',
    ],

    'sort' => [
        'label' => 'Sort by',
        'date' => 'Payment date',
        'risk' => 'Risk',
        'amount' => 'Amount',
    ],

    'col' => [
        'id' => 'Subscription ID',
        'risk' => 'Risk level',
        'customer' => 'Customer',
        'email' => 'Email',
        'method' => 'Payment method status',
        'date' => 'Payment date',
        'amount' => 'Amount',
        'retries' => 'Retries left',
        'last_status' => 'Last payment status',
        'last_error' => 'Last error message',
        'source' => 'Payment source',
    ],

    'risk' => [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ],

    'method' => [
        'valid' => 'Valid',
        'expiring' => 'Expiring',
        'expiring_on' => 'Expiring :date',
        'expired' => 'Expired',
        'missing' => 'Missing',
    ],

    'status' => [
        'succeeded' => 'Succeeded',
        'refunded' => 'Refunded',
        'failed' => 'Failed',
        'retry_scheduled' => 'Retrying',
        'pending' => 'Pending',
    ],

    'pager' => [
        'showing' => 'Showing :from–:to of :total',
        'page' => ':page / :pages',
        'previous' => 'Previous',
        'next' => 'Next',
    ],

    'by_day' => [
        'title' => 'Scheduled by day',
        'subtitle' => 'Open a day to see who is charged in Subscriptions',
        'count' => '{1} :count payment|[0,*] :count payments',
        'link' => 'Open this day in Subscriptions',
    ],

    'empty' => [
        'title' => 'Nothing scheduled',
        'body' => 'No charge is due in this window. Try a longer one.',
    ],
];
