<?php

// Analytics › Products › Churn & retention. Read as __('analytics/products_churn.key').
// Mirror in lang/he/analytics/products_churn.php.
return [
    'no_product' => 'No product recorded',
    'unknown_product' => 'Product #:id',

    'measure' => [
        'label' => 'Measure',
        'mrr' => 'MRR',
        'quantity' => 'Quantity',
    ],

    'trend' => [
        'title' => 'Product-wise churn',
        'subtitle_mrr' => 'Churned MRR by product · :top across the products drawn, of :total lost',
        'subtitle_quantity' => 'Churned units by product · :top across the products drawn, of :total lost',
        'note' => 'Churn = a subscription leaving the active book by cancellation or a payment lapse. A switch to another product from an account-area offer is a swap, not churn.',
    ],

    'reasons' => [
        'title' => 'Cancellation-reason-wise churn',
        'subtitle' => 'By reason, across products, in the selected period',
        'caption_mrr' => 'churned MRR',
        'caption_quantity' => 'churned units',
        'none' => 'No reason given',
        'payment_failed' => 'Payment failed',
        'customer_portal' => 'Customer cancelled (portal)',
        'customer_area' => 'Customer cancelled (account area)',
        'note' => 'Reasons are the text written on the cancellation; LETS has no fixed reason list yet.',
    ],

    'flow' => [
        'title' => 'Cancellation flow effectiveness · top products',
        'subtitle' => 'Subscribed quantity against cancellation attempts, with each product\'s save rate',
        'body' => 'LETS has no cancellation flow, so attempts and saves are not recorded. A customer who cancels is cancelled.',
    ],

    'table' => [
        'title' => 'Churn and retention by product',
        'subtitle' => 'Per product and variant, the selected period',
        'product' => 'Product',
        'variant' => 'Variant',
        'subscribed' => 'Subscribed qty',
        'attempts' => 'Cancellation attempts (%)',
        'saved' => 'Saved qty (%)',
        'cancelled' => 'Cancelled qty',
        'rate' => 'Cancellation rate',
        'note' => 'Subscribed qty is today\'s. Cancellation rate = units cancelled in the period ÷ the product\'s units at the period\'s start. Attempts and saves: not tracked (no cancellation flow).',
    ],
];
