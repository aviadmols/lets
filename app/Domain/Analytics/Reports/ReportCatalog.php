<?php

namespace App\Domain\Analytics\Reports;

/**
 * The report library of Analytics › Reports (spec §8): every report the
 * sketch lists, by category, and — for the ones LETS has data for — the
 * method of ReportDefinitions that builds it. A report with no builder renders
 * as "Not tracked yet" (docs/analytics/data-map.md §8), never as a button that
 * downloads an empty file.
 *
 * Labels: analytics/reports_reports.category.<category> and
 * analytics/reports_reports.report.<key>.{title,body}.
 */
final class ReportCatalog
{
    // === CONSTANTS ===
    /** category => [report key => builder method on ReportDefinitions, or null = not tracked] */
    public const REPORTS = [
        'customers' => [
            'subscribers' => 'subscribers',
            'subscriber_summary' => 'subscriberSummary',
        ],
        'subscriptions' => [
            'subscriptions' => 'subscriptions',
            'subscriptions_summary' => 'subscriptionsSummary',
            'subscription_activity_logs' => 'activityLogs',
            'streak_enrolled' => null,
            'streak_cohorts' => null,
            'streak_orders' => null,
        ],
        'orders' => [
            'scheduled_upcoming_orders' => 'upcomingOrders',
            'checkout_orders' => null,
            'processed_orders' => null,
            'skipped_orders' => null,
            'checkout_order_line_items' => null,
            'processed_order_line_items' => null,
            'subscription_orders_funnel' => null,
        ],
        'products' => [
            'subscribed_products' => 'subscribedProducts',
            'subscriber_distribution_by_product' => null,
        ],
        'bundles' => [
            'bundle_orders' => null,
            'bundle_sales' => null,
        ],
        'payments' => [
            'transaction_logs' => 'transactionLogs',
            'transaction_summary' => 'transactionSummary',
        ],
        'acquisition' => [
            'product_wise_acquisition' => 'productAcquisition',
        ],
        'revenue' => [
            'subscription_sales' => 'subscriptionSales',
            'product_wise_sales' => 'productSales',
        ],
        'upsells' => [
            'product_wise_upsells' => 'productUpsells',
            'product_wise_upsell_revenue' => 'productUpsellRevenue',
            'upsell_profiles_performance' => null,
            'upsell_profiles_revenue' => null,
            'upsell_quick_actions' => null,
            'upsell_campaigns' => null,
        ],
        'cancellations' => [
            'product_variant_cancellation' => null,
            'cancellation_logs' => 'cancellationLogs',
        ],
        'inventory' => [
            'upcoming_orders_inventory' => null,
            'out_of_stock_summary' => null,
            'inventory_logs' => null,
            'inventory_forecast' => null,
        ],
        'prepaid' => [
            'prepaid_credit_transactions' => null,
        ],
    ];

    /** Reports that read the selected date range (the rest are "as of now"). */
    public const RANGED = [
        'subscription_activity_logs', 'transaction_logs', 'transaction_summary', 'product_wise_acquisition',
        'subscription_sales', 'product_wise_sales', 'product_wise_upsells', 'product_wise_upsell_revenue', 'cancellation_logs',
    ];

    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(self::REPORTS);
    }

    public static function method(string $report): ?string
    {
        foreach (self::REPORTS as $reports) {
            if (array_key_exists($report, $reports)) {
                return $reports[$report];
            }
        }

        return null;
    }

    public static function exists(string $report): bool
    {
        foreach (self::REPORTS as $reports) {
            if (array_key_exists($report, $reports)) {
                return true;
            }
        }

        return false;
    }

    public static function isAvailable(string $report): bool
    {
        return self::method($report) !== null;
    }

    public static function isRanged(string $report): bool
    {
        return in_array($report, self::RANGED, true);
    }
}
