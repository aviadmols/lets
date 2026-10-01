<?php

// Analytics › Cancellations › Risk analysis (+ App\Livewire\Analytics\RiskTable).
// Read as __('analytics/cancellations_risk.key'). Mirror in lang/he/analytics/cancellations_risk.php.
return [
    'kpi' => [
        'at_risk' => 'Subscriptions at risk',
        'high' => 'High risk',
        'expiring' => 'Cards expiring soon',
        'mrr' => 'MRR at risk',
    ],

    'caption' => [
        'of_live' => ':share of :live live subscriptions · as of today',
        'high' => '2+ failed orders in a row, lapsed billing or a dead card',
        'expiring' => 'within :days days, on live subscriptions',
        'mrr' => ':share of live MRR',
    ],

    'table' => [
        'title' => 'Subscriptions most likely to churn next',
        'subtitle' => 'Failed orders in a row, expiring cards and short histories — riskiest first',
        'note' => 'High: 2+ failed orders in a row, billing already failed, or the card expired. Medium: one failure, a card expiring within 60 days, or under 70% success. Low: one order or fewer so far, or under 90% success. Success % counts charges that ended collected.',
    ],

    'filter' => [
        'search' => 'Search ID, name or email',
        'level' => 'Risk level',
        'card' => 'Card status',
        'all_levels' => 'All risk levels',
        'all_cards' => 'All card statuses',
    ],

    'export' => 'Export',

    'col' => [
        'subscription' => 'Subscription',
        'risk' => 'Risk',
        'status' => 'Status',
        'created' => 'Created on',
        'customer' => 'Customer',
        'email' => 'Email',
        'price' => 'Price',
        'orders' => 'Completed orders',
        'success' => 'Success %',
        'streak' => 'Failed in a row',
        'card' => 'Card status',
        'expiry' => 'Card expiry',
        'interval' => 'Billing interval',
    ],

    'level' => [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ],

    'card' => [
        'valid' => 'Valid',
        'expiring' => 'Expiring',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
        'none' => 'No card details',
    ],

    'status' => [
        'active' => 'Active',
        'paused' => 'Paused',
        'failed' => 'Failed',
        'awaiting_payment' => 'Awaiting payment',
    ],

    'empty' => [
        'title' => 'No subscription matches',
        'body' => 'Nothing at risk fits this search and these filters.',
    ],

    'pager' => [
        'showing' => ':from–:to of :total at risk',
        'legend' => 'Success % is red below 70% and amber below 90%',
        'page' => 'Page :page of :pages',
        'previous' => 'Previous page',
        'next' => 'Next page',
    ],
];
