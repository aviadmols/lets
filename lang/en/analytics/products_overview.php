<?php

// Analytics › Products › Overview. Read as __('analytics/products_overview.key').
// Mirror in lang/he/analytics/products_overview.php.
return [
    'no_product' => 'No product recorded',
    'unknown_product' => 'Product #:id',

    'kpi' => [
        'subscribers' => 'Active subscribers',
        'quantity' => 'Active subscribed products quantity',
        'upsells' => 'One-time upsells billed',
    ],

    'by_product' => [
        'title' => 'Active subscribers by product',
        'subtitle' => 'Share of the :count active subscribers · active today',
        'caption' => 'subscribers',
    ],

    'acquisition' => [
        'title' => 'Product-wise acquisition count',
        'subtitle' => '{0} No subscriptions acquired this period|{1} :count subscription acquired this period, by current status|[2,*] :count subscriptions acquired this period, by current status',
    ],

    'status' => [
        'active' => 'Active',
        'cancelled' => 'Cancelled',
        'paused' => 'Paused',
        'expired' => 'Expired',
        'swapped' => 'Swapped / removed',
        'lapsed' => 'Payment lapsed',
    ],

    'acquisition_revenue' => [
        'title' => 'Product-wise acquisition revenue',
        'subtitle' => 'Checkout subscription revenue this period · :amount from :orders checkout orders',
        'toggle' => 'Unit',
        'revenue' => 'Revenue',
        'quantity' => 'Quantity',
        'note' => 'Checkout = a subscription\'s first charge collected through LETS (for an imported subscription, its first charge here). Shopify-Payments contracts take their checkout in Shopify and are not counted.',
    ],

    'table' => [
        'title' => 'Products',
        'subtitle' => 'Quantities and revenue per subscribed product · movements in the selected period',
        'product' => 'Product',
        'variant' => 'Variant',
        'new' => 'New qty',
        'active' => 'Active qty',
        'revenue' => 'Subscription revenue',
        'per_payer' => 'Revenue per subscriber',
        'cancelled' => 'Cancelled',
        'paused' => 'Paused',
        'expired' => 'Expired',
        'swapped' => 'Swapped / removed',
        'summary' => ':products products · new qty :new · cancelled :cancelled · paused :paused · expired :expired · subscription revenue :revenue in total. Swapped counts switches made from an account-area offer; revenue per subscriber divides by the subscribers charged in the period.',
    ],
];
