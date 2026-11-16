<?php

// Analytics › Upsells › Added. Read as __('analytics/upsells_added.key').
// Mirror in lang/he/analytics/upsells_added.php. Every step counts once per
// order: a page reload or a double tap adds nothing.
return [
    'channel' => [
        'label' => 'Channel',
        'all' => 'All channels',
        'checkout' => 'Thank-you page',
        'account_area' => 'Account area',
    ],

    'steps' => [
        'title' => 'The offer\'s path',
        'subtitle' => 'Each step counts once per order. A page reload or a double tap adds nothing.',
        'shown' => [
            'step' => '1 · Offer shown',
            'unit' => 'orders saw an offer',
            'rate' => 'Starting point',
            'untracked' => 'The account area records no views',
        ],
        'accepted' => [
            'step' => '2 · Accepted',
            'unit' => 'orders said "yes"',
            'rate' => ':accepted of :shown who saw it (:rate)',
        ],
        'charged' => [
            'step' => '3 · Actually charged',
            'unit' => 'came in',
            'rate' => ':rate of the amount accepted',
        ],
        'pending' => [
            'step' => 'Not charged (yet)',
            'unit' => 'accepted, not charged',
            'rate' => 'Worth a look',
            'none' => 'All charged',
        ],
    ],

    'kpi' => [
        'charged' => 'Actual revenue from offers',
        'charged_formula' => 'Successful charges, net of refunds',
        'avg' => 'Average per accepting order',
        'avg_formula' => 'Amount accepted ÷ orders that accepted',
        'avg_note' => 'One order can take more than one offer',
        'rate' => 'Conversion rate',
        'rate_formula' => 'Orders that accepted ÷ orders that saw an offer',
        'rate_untracked' => 'The account area records no views, so there is no rate',
    ],

    'trend' => [
        'title' => 'Over time',
        'subtitle' => 'Accepted vs actually charged',
        'agreed' => 'Accepted',
        'charged' => 'Actually charged',
        'partial' => 'The last column is a period still in progress.',
    ],

    'offers' => [
        'title' => 'Performance by offer',
        'subtitle' => 'Every offer, from view to money',
        'offer' => 'Offer',
        'channel' => 'Channel',
        'price' => 'Price',
        'shown' => 'Shown to',
        'accepted' => 'Accepted',
        'rate' => 'Conversion',
        'charged' => 'Actually charged',
        'pending' => 'Not charged',
        'unnamed' => 'Untitled offer',
        'orders' => '{1} 1 order|[2,*] :count orders',
        'note' => '"Accepted" is valued at the offer\'s current price. "Actually charged" is money that came in, net of refunds. Admin-added extras and campaign upsells are not tracked yet.',
    ],
];
