<?php

// Analytics › Cancellations › Overview. Read as __('analytics/cancellations_overview.key').
// Also the shared names of reasons / channels / products for every Cancellations screen
// (App\Domain\Analytics\Cancellations\CancellationLabels). Mirror in lang/he/analytics/cancellations_overview.php.
return [
    'section' => [
        'subscriber' => 'Subscriber churn metrics',
        'subscription' => 'Subscription churn metrics',
    ],

    'kpi' => [
        'churn_rate' => 'Churn rate',
        'lost' => 'Subscribers lost',
        'zero_day' => '0-day churn contribution',
        'upcoming' => 'Upcoming order contribution',
        'cancellation_rate' => 'Cancellation rate',
        'cancelled' => 'Subscriptions cancelled',
        'orders_before' => 'Orders before cancellation',
        'mrr_lost' => 'MRR lost',
    ],

    'caption' => [
        'of_start' => ':n of :start active at period start',
        'zero_day' => ':n of :lost churned the day they subscribed',
        'orders_avg' => 'average completed orders',
        'mrr_share' => ':share of active MRR at period start',
    ],

    'trend' => [
        'title' => 'Churn trends',
        'metric_label' => 'Metric',
        'subtitle' => [
            'weekly' => 'Last :n weeks',
            'monthly' => 'Last :n months',
        ],
        'metric' => [
            'churn' => 'Churn rate',
            'cancellation' => 'Cancellation rate',
            'orders' => 'Orders before',
            'mrr' => 'MRR lost',
        ],
        'series' => [
            'lost' => 'Subscribers lost',
            'churn_rate' => 'Churn rate (end axis)',
            'cancelled' => 'Subscriptions cancelled',
            'cancellation_rate' => 'Cancellation rate (end axis)',
            'orders_before' => 'Average orders before cancellation (end axis)',
            'mrr_lost' => 'MRR lost',
        ],
    ],

    'products' => [
        'title' => 'Top 10 products by churned MRR',
        'subtitle' => 'MRR cancelled in the period · % of the product\'s MRR (active today + cancelled)',
        'display' => ':mrr · :share',
    ],

    'order_wise' => [
        'title' => 'Order-wise cancellations',
        'subtitle' => 'By completed orders · % = cancelled ÷ (active today + cancelled)',
        'active' => 'Active subscriptions at this order count',
        'cancelled' => 'Cancelled in the period',
        'bucket_plus' => ':n+',
    ],

    'reasons' => [
        'title' => 'Reason-wise cancellations',
        'caption' => 'cancelled',
        'note' => 'The reason typed when the subscription was cancelled. LETS has no reason list on its cancel flow yet, so most cancellations carry none.',
    ],

    'channels' => [
        'title' => 'Channel-wise cancellations',
        'note' => 'Where the cancellation was made.',
    ],

    'reason_trend' => [
        'title' => 'Reason-wise cancellation trend',
        'subtitle' => 'Cancelled subscriptions per reason',
    ],

    'by_frequency' => ['title' => 'Frequency-wise cancellations'],
    'by_plan' => ['title' => 'Selling-plan-wise cancellations'],

    'dimension' => [
        'subtitle' => 'Cancelled in the period, of active today + cancelled',
        'row' => ':n of :base · :share',
    ],

    'channel' => [
        'account_area' => 'Account area',
        'customer_portal' => 'Customer portal',
        'customer' => 'Customer',
        'admin' => 'Admin',
        'platform' => 'LETS support',
        'payment_failed' => 'Payment failed',
        'plan_switch' => 'Switched plan',
        'refund' => 'Order refunded',
        'store' => 'Store admin',
        'automatic' => 'Automatic',
    ],

    'reason' => [
        'none' => 'No reason given',
        'payment_failed' => 'Payment failed',
        'switched' => 'Switched to another plan',
        'refunded' => 'Order refunded',
    ],

    'product' => [
        'unknown' => 'Unknown product',
        'id' => 'Product :id',
    ],

    'export' => [
        'date' => 'Cancelled at',
        'subscription' => 'Subscription',
        'customer' => 'Customer',
        'product' => 'Product',
        'frequency' => 'Frequency',
        'mrr' => 'MRR',
        'orders' => 'Completed orders',
        'channel' => 'Channel',
        'reason' => 'Reason',
    ],
];
