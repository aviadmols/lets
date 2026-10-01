<?php

// Analytics › Upsells › Sold. Read as __('analytics/upsells_sold.key').
// Mirror in lang/he/analytics/upsells_sold.php.
return [
    'channel' => [
        'label' => 'Channel',
        'all' => 'All',
        'checkout' => 'Thank-you / post-purchase',
        'account_area' => 'Account area',
    ],

    'kpi' => [
        'items' => 'Upsells sold',
        'revenue' => 'Net revenue generated',
        'avg' => 'Avg upsell value per order',
        'contribution' => 'Revenue contribution',
    ],

    'performance' => [
        'title' => 'Performance over time',
        'subtitle' => 'Revenue realised · :total in total',
        'partial' => 'The last bar is a period still in progress.',
    ],

    'by_channel' => [
        'title' => 'Channel-wise revenue',
        'subtitle' => 'Where the billed upsells came from',
        'caption' => 'revenue realised',
        'untracked' => 'Admin-added extras and campaign upsells are not tracked yet.',
    ],

    'items' => [
        'title' => 'Item-wise performance',
        'subtitle' => 'Billed upsells by item, the selected period',
        'item' => 'Item',
        'channel' => 'Channel',
        'sold' => 'Sold',
        'revenue' => 'Net revenue',
        'avg' => 'Avg price',
        'share' => 'Share',
        'unnamed' => 'Unnamed item',
    ],

    'note' => 'Net of refunds. Revenue contribution = upsell revenue ÷ all money collected through LETS in the period (:collected). Shopify\'s post-purchase step is charged by Shopify and not confirmed back to LETS, so it appears under Added only.',
];
