<?php

// Analytics › Forecast. Read as __('analytics/forecast_overview.key').
// Mirror in lang/he/analytics/forecast_overview.php.
return [
    'meta' => 'Next 90 days · :span · from :active active subscriptions',
    'meta_rate' => ':rate charge-success rate (:n charges, last 90 days)',
    'meta_no_rate' => 'no charges in the last 90 days to weigh the schedule',

    'kpi' => [
        'expected' => 'Next :days days · expected recurring revenue',
    ],

    'caption' => [
        'orders' => ':orders scheduled charges · :scheduled scheduled',
        'band' => '95% band :low – :high',
        'no_rate' => ':orders scheduled charges · not weighted: no charge history yet',
    ],

    'chart' => [
        'title' => 'Expected weekly recurring revenue',
        'subtitle' => 'Next :weeks weeks · :total in total · the last week has :days days, ending :end',
        'vs_last_year' => ':delta vs the same weeks last year',
        'expected' => 'Expected',
        'scheduled' => 'Scheduled',
        'band' => 'Upper 95% band',
        'last_year' => 'Collected in the same weeks last year',
    ],

    'table' => [
        'title' => 'By kind of subscription',
        'subtitle' => 'Each kind is weighed by its own success rate',
        'kind' => 'Kind',
        'attempts' => 'Charges (90 days)',
        'rate' => 'Success rate',
        'band' => '95% band',
        'orders' => 'Scheduled charges',
        'scheduled' => 'Scheduled',
        'expected' => 'Expected',
        'pooled' => '(all kinds)',
    ],

    'kind' => [
        'recurring' => 'Recurring subscriptions',
        'installments' => 'Installment plans',
        'contract' => 'Shopify subscriptions',
    ],

    'how' => [
        'title' => 'How it is computed',
        'method' => 'Every active subscription\'s next charge date is stepped forward by its billing interval to the 90-day horizon and multiplied by its charge amount. Each charge is then weighed by the charge-success rate of its kind over the last 90 days — counted on charges that were eventually collected, after retries. Paused, awaiting-activation and free subscriptions are left out; an installment plan stops after its remaining payments; a charge already overdue is counted today.',
        'band' => 'The band is the 95% confidence interval of each kind\'s success rate: with few past charges it is wide, with many it is narrow. It does not cover cancellations, price changes or new subscriptions that have not happened yet.',
        'limits' => 'Numbers refresh every few minutes and are an estimate, not a guarantee of revenue.',
    ],

    'empty' => [
        'title' => 'Nothing scheduled yet',
        'body' => 'There are no active subscriptions with a next charge date to forecast from.',
    ],

    'export' => [
        'week_start' => 'Week start',
        'week_end' => 'Week end',
        'orders' => 'Scheduled charges',
        'scheduled' => 'Scheduled amount',
        'expected' => 'Expected',
        'low' => 'Band low',
        'high' => 'Band high',
        'last_year' => 'Collected same week last year',
    ],
];
