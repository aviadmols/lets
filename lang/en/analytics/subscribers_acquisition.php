<?php

// Analytics › Subscribers › Acquisition. Read as __('analytics/subscribers_acquisition.key').
// Mirror in lang/he/analytics/subscribers_acquisition.php.
return [
    'kpi' => [
        'non_subscribed' => 'Non-subscribed customers',
        'acquired' => 'Acquired subscribers',
        'acquired_caption' => ':n of :additions subscriber additions',
        'rate' => 'Subscriber acquisition rate',
        'zero_day' => '0-day subscriber churn',
        'zero_day_caption' => ':share of acquired · cancelled the day they subscribed',
    ],

    'trend' => [
        'title' => 'Acquisition trend',
        'subtitle' => 'New subscribers per period',
        'series' => 'Acquired subscribers',
        'note' => 'The acquisition rate needs customers who never subscribed, which LETS does not record — the line shows acquired subscribers.',
    ],

    'product' => [
        'title' => 'Subscriber acquisition by product',
        'subtitle' => 'Subscriptions started this period',
        'by_quantity' => 'By subscribed product quantity',
        'by_revenue' => 'By checkout revenue',
        'units' => ':n units',
        'unknown' => 'No product',
        'unnamed' => 'Product :id',
        'note' => 'Checkout revenue is each new subscription\'s first charge, net of refunds. Shopify-Payments subscriptions are not split by product.',
    ],

    'order_number' => [
        'title' => 'Subscriber acquisition by order number',
        'subtitle' => 'At which order customers subscribed',
        'not_tracked' => 'This needs every customer\'s order history, including customers who never subscribed. LETS does not ingest store orders yet.',
    ],

    'order_rate' => [
        'title' => 'Order-wise acquisition rate trend',
    ],

    'table' => [
        'title' => 'Acquisition by selling plan × frequency',
        'subtitle' => 'Subscribers acquired this period, by the subscription that made them a subscriber',
        'plan' => 'Selling plan',
        'frequency' => 'Frequency',
        'subscribers' => 'Acquired subscribers',
        'mrr' => 'Acquired MRR',
        'zero_day' => '0-day churn',
    ],
];
