<?php

// Analytics › Upsells › Added. Read as __('analytics/upsells_added.key').
// Mirror in lang/he/analytics/upsells_added.php.
return [
    'channel' => [
        'label' => 'Channel',
        'all' => 'All',
        'checkout' => 'Thank-you / post-purchase',
        'account_area' => 'Account area',
    ],

    'kpi' => [
        'items' => 'Items added',
        'revenue' => 'Net revenue added',
        'avg' => 'Avg upsell value per order',
        'sold' => 'Actual sold (to date)',
        'sold_share' => ':share of revenue added',
    ],

    'performance' => [
        'title' => 'Performance over time',
        'subtitle' => 'Revenue added · :total in total',
        'partial' => 'The last bar is a period still in progress.',
    ],

    'by_channel' => [
        'title' => 'Channel-wise revenue',
        'subtitle' => 'Where the upsells were added',
        'caption' => 'revenue added',
        'untracked' => 'Admin-added extras and campaign upsells are not tracked yet.',
    ],

    'items' => [
        'title' => 'Item-wise performance',
        'subtitle' => 'Top items by count · :count items added',
        'item' => 'Item',
        'channel' => 'Channel',
        'price' => 'Price',
        'added' => 'Added',
        'revenue' => 'Revenue added',
        'share' => 'Share',
        'unnamed' => 'Unnamed offer',
        'others' => '{1} All other items (:count)|[2,*] All other items (:count)',
        'note' => 'Thank-you and post-purchase items are valued at the offer\'s current price — offer prices are not versioned.',
    ],

    'profiles' => [
        'title' => 'Upsell profiles performance',
        'subtitle' => 'Offers shown vs accepted, per upsell flow and account offer',
        'profile' => 'Profile',
        'shown' => 'Shown',
        'accepted' => 'Accepted',
        'rate' => 'Rate',
        'revenue' => 'Revenue added',
        'unnamed' => 'Profile #:id',
        'note' => 'The account area records acceptances, not impressions — its Shown is not tracked.',
    ],

    'sold' => [
        'title' => 'Sold (to date)',
        'subtitle' => 'Upsells actually billed in this period',
        'items' => 'Upsells sold',
        'revenue' => 'Net revenue',
        'avg' => 'Avg per order',
        'contribution' => 'Revenue contribution',
        'link' => 'Open the Sold tab →',
    ],
];
