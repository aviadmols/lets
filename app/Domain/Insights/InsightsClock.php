<?php

namespace App\Domain\Insights;

use App\Models\InstallmentPayment;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The calendar the merchant analytics screens count on.
 *
 * Days are the MERCHANT's days (Israel), not the server's UTC ones: a renewal charged at
 * 23:30 UTC is tomorrow's sale in Tel Aviv, and a daily chart that put it on the wrong
 * bar would disagree with the merchant's own bank statement.
 */
final class InsightsClock
{
    // === CONSTANTS ===
    public const TIMEZONE = 'Asia/Jerusalem';

    public const DATE = 'Y-m-d';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /** A stored timestamp (UTC) as the merchant's calendar day, or null. */
    public static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone(self::TIMEZONE)->format(self::DATE);
    }

    /**
     * The day this shop's numbers start (config insights.start_dates), else the shop's
     * first charge through the system, else today. Tenant-scoped read.
     */
    public static function start(Shop $shop): CarbonImmutable
    {
        $configured = config('insights.start_dates.'.$shop->getKey());
        if (is_string($configured) && $configured !== '') {
            return CarbonImmutable::parse($configured, self::TIMEZONE)->startOfDay();
        }

        $first = InstallmentPayment::query()
            ->where('status', PaymentStatus::SUCCEEDED->value)
            ->min(DB::raw('COALESCE(charged_at, created_at)'));

        return $first !== null
            ? CarbonImmutable::parse(self::day($first), self::TIMEZONE)
            : self::today();
    }
}
