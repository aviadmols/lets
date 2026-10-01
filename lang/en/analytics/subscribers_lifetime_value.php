<?php

// Analytics › Subscribers › Lifetime value. Read as __('analytics/subscribers_lifetime_value.key').
// Mirror in lang/he/analytics/subscribers_lifetime_value.php.
return [
    'summary' => [
        'lead_before' => 'Aggregate subscriber LTV is',
        'lead_after' => 'across :n subscriber, as of :date|across :n subscribers, as of :date',
        'empty' => 'No subscriber has been billed yet, so there is no lifetime value to show.',
        'note' => 'LTV = subscription revenue realised (net of refunds) ÷ customers who ever subscribed. The comparison with non-subscriber LTV needs the store\'s other orders, which LETS does not ingest yet.',
    ],

    'segments' => [
        'title' => 'LTV by segment',
        'subtitle' => 'By the order at which a customer subscribed',
        'not_tracked' => 'Segments by order number (#1, #2, #3, #3+) and non-subscriber LTV need every customer\'s order history. LETS does not ingest store orders yet.',
    ],

    'distribution' => [
        'title' => 'Customer distribution',
        'not_tracked' => 'Needs the same order history as the segments.',
    ],

    'segment' => [
        'subscribers' => 'All subscribers',
        'non_subscribers' => 'Non-subscribers',
    ],

    'table' => [
        'title' => 'LTV breakdown',
        'subtitle' => 'Customers who subscribed by the end of the period',
        'segment' => 'Segment',
        'customers' => 'Customer count',
        'orders' => 'Total orders',
        'revenue' => 'Total revenue',
        'per_customer' => 'Orders per customer',
        'ltv' => 'LTV',
        'note' => 'Orders are subscription charges LETS billed; a Shopify subscription\'s checkout order lives in Shopify. A dash means not tracked yet.',
    ],

    'trend' => [
        'title' => 'LTV trend',
        'subtitle' => 'Subscriber LTV at the end of each period',
        'note' => 'Each point divides all revenue realised so far by every customer who had subscribed by then.',
    ],
];
