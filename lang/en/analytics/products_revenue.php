<?php

// Analytics › Products › Revenue. Read as __('analytics/products_revenue.key').
// Mirror in lang/he/analytics/products_revenue.php.
return [
    'no_product' => 'No product recorded',
    'unknown_product' => 'Product #:id',

    'kpi' => [
        'revenue' => 'Subscription revenue',
        'checkout' => 'Checkout revenue',
        'recurring' => 'Recurring revenue',
        'orders' => 'Subscription orders',
    ],

    'type' => [
        'recurring' => 'Recurring',
        'checkout' => 'Checkout',
    ],

    'trend' => [
        'title' => 'Product-wise subscription revenue trend',
        'subtitle' => 'Net revenue per product, top five products; the rest as Other',
    ],

    'orders' => [
        'title' => 'Product-wise orders count',
        'subtitle' => 'Orders charged this period, Recurring vs Checkout',
    ],

    'revenue' => [
        'title' => 'Product-wise revenue',
        'subtitle' => 'Net revenue this period, Recurring vs Checkout',
    ],

    'table' => [
        'title' => 'Revenue by product',
        'subtitle' => 'Per product and variant, the selected period',
        'product' => 'Product',
        'variant' => 'Variant',
        'revenue' => 'Total revenue',
        'checkout_orders' => 'Checkout orders',
        'checkout_revenue' => 'Checkout revenue',
        'recurring_orders' => 'Recurring orders',
        'recurring_revenue' => 'Recurring revenue',
        'units' => 'Subscription quantity sold',
    ],

    'note' => 'Revenue collected through LETS, net of refunds; upsell and add-on charges are excluded (see Upsells). Checkout = a subscription\'s first charge collected here (an imported subscription\'s first charge in LETS counts too). Shopify-Payments contracts appear as recurring only — Shopify takes their checkout — and a contract\'s amount is split across its lines by units.',
];
