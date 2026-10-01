<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Support\Frequency;

/**
 * Display names for the keys the Cancellations queries return — reason
 * groups, channels, products, selling plans — in the active locale. One place,
 * so the Overview, Order-wise churn, the export and the report all name a
 * thing the same way. Copy lives in lang/{en,he}/analytics/cancellations_overview.php.
 */
final class CancellationLabels
{
    // === CONSTANTS ===
    public const LANG = 'analytics/cancellations_overview.';

    public static function reason(string $key, string $text = ''): string
    {
        if ($key === CancellationsOverviewQuery::OTHER) {
            return __('analytics.other');
        }
        if (CancellationReasons::isSystemReason($key)) {
            return __(self::LANG.'reason.'.ltrim($key, '_'));
        }

        return $text !== '' ? $text : $key;
    }

    public static function channel(string $key): string
    {
        return __(self::LANG.'channel.'.$key);
    }

    public static function product(string $key, string $title = ''): string
    {
        return match (true) {
            $key === CancellationLog::PRODUCT_CONTRACTS => __('analytics.selling_plan.shopify'),
            trim($title) !== '' => trim($title),
            $key === CancellationLog::PRODUCT_UNKNOWN => __(self::LANG.'product.unknown'),
            default => __(self::LANG.'product.id', ['id' => $key]),
        };
    }

    public static function sellingPlan(string $key, ?string $name): string
    {
        return match ($key) {
            ActiveBook::PLAN_NONE => __('analytics.selling_plan.none'),
            ActiveBook::PLAN_SHOPIFY => __('analytics.selling_plan.shopify'),
            default => trim((string) $name) !== '' ? (string) $name : __('analytics.selling_plan.unnamed', ['id' => $key]),
        };
    }

    public static function frequency(string $key): string
    {
        return Frequency::label($key);
    }
}
