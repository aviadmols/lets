<?php

// Analytics module — the SHARED shell + component copy. Mirror in lang/he/analytics.php.
// Each screen keeps its own copy in lang/{en,he}/analytics/<section>_<sub>.php
// (read as __('analytics/<section>_<sub>.key')) so screens never edit one shared file.
// Hebrew vocabulary: מנויים = subscribers (people), מינויים = subscriptions (plans).
return [
    'title' => 'Analytics',
    'sections_label' => 'Analytics sections',
    'export' => 'Export',
    'export_unavailable' => 'This screen has nothing to export yet.',
    'view_report' => 'View report →',
    'other' => 'Other',

    'sections' => [
        'subscribers' => 'Subscribers',
        'cohorts' => 'Cohorts',
        'payments' => 'Payments',
        'products' => 'Products',
        'upsells' => 'Upsells',
        'cancellations' => 'Cancellations',
        'forecast' => 'Forecast',
        'reports' => 'Reports',
    ],

    'subtabs' => [
        'subscribers' => [
            'overview' => 'Overview',
            'acquisition' => 'Acquisition',
            'order_funnel' => 'Order funnel',
            'revenue' => 'Revenue',
            'lifetime_value' => 'Lifetime value',
        ],
        'cohorts' => ['overview' => 'Cohorts'],
        'payments' => [
            'overview' => 'Overview',
            'recovery' => 'Recovery',
            'failures' => 'Failures',
            'upcoming' => 'Upcoming payments',
        ],
        'products' => [
            'overview' => 'Overview',
            'revenue' => 'Revenue',
            'churn' => 'Churn & retention',
        ],
        'upsells' => [
            'added' => 'Added',
            'sold' => 'Sold',
        ],
        'cancellations' => [
            'overview' => 'Overview',
            'saves' => 'Saves',
            'order_wise' => 'Order-wise churn',
            'risk' => 'Risk analysis',
        ],
        'forecast' => ['overview' => 'Forecast'],
        'reports' => [
            'reports' => 'Reports',
            'exports' => 'Exports',
        ],
    ],

    'platform' => [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ],

    'range' => [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'mtd' => 'Month to date',
        'ytd' => 'Year to date',
        'custom' => 'Custom range',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Apply',
    ],

    'compare' => [
        'label' => 'Compare: :mode',
        'previous_period' => 'previous period',
        'previous_year' => 'previous year',
        'none' => 'none',
        'compared_with' => 'compared with :span',
        'vs_previous_days' => 'vs previous :days days',
        'vs_last_year' => 'vs same period last year',
        'vs_span' => 'vs :span',
    ],

    'filters' => [
        'country' => 'Country',
        'products' => 'Products',
        'plans' => 'Selling plans',
        'frequencies' => 'Frequencies',
        'country_hint' => 'LETS stores no customer country — every shop sells in Israel today.',
        'n_selected' => ':count selected',
        'clear' => 'Clear :filter',
        'none_available' => 'Nothing to filter by yet.',
    ],

    'grain' => [
        'label' => 'Granularity',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ],

    'frequency' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'every_days' => 'Every :count days',
        'every_weeks' => 'Every :count weeks',
        'every_months' => 'Every :count months',
        'every_years' => 'Every :count years',
    ],

    'selling_plan' => [
        'none' => 'No selling plan',
        'shopify' => 'Shopify subscriptions',
        'unnamed' => 'Plan #:id',
    ],

    // Movements in and out of the active book, at both levels.
    'movement' => [
        'subscriber' => [
            'new' => 'New',
            'reactivated' => 'Reactivated',
            'resumed' => 'Resumed',
            'paused' => 'Paused',
            'cancelled' => 'Churned',
            'expired' => 'Expired',
        ],
        'subscription' => [
            'new' => 'New',
            'reactivated' => 'Reactivated',
            'resumed' => 'Resumed',
            'paused' => 'Paused',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired',
        ],
    ],

    'delta' => [
        'points' => ':value pts',
        'sr_good' => '(a good change)',
        'sr_bad' => '(a bad change)',
        'sr_flat' => '(no change)',
    ],

    'empty' => [
        'no_data_title' => 'No data in this period',
        'no_data_body' => 'Nothing happened here in the selected dates. Try a longer range.',
        'not_tracked_title' => 'Not tracked yet',
        'not_tracked_body' => 'LETS does not record what this needs yet, so it shows nothing rather than a guess.',
    ],

    'table' => [
        'period' => 'Period',
        'cohort' => 'Cohort',
        'size' => 'Subscribers',
    ],

    'placeholder' => [
        'badge' => 'Coming in this build',
        'title' => 'This screen is on its way',
        'body' => 'It is part of the approved Analytics design and is being built now. The tabs, dates and filters above already work.',
        'spec' => 'Spec :spec',
        'sketch' => 'Sketch :sketch',
    ],
];
