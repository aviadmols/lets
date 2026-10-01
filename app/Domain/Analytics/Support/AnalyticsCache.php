<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Support\Tenant;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A few minutes of memory for Analytics aggregates, per shop + period + filters.
 *
 * The key ALWAYS starts with the bound shop id, so one tenant's numbers can
 * never be served to another; with no tenant bound nothing is cached (and the
 * BelongsToShop scope returns nothing anyway). Short TTL on purpose: a
 * merchant who just cancelled a subscription should see it on the next look,
 * not tomorrow.
 */
final class AnalyticsCache
{
    // === CONSTANTS ===
    public const TTL_SECONDS = 300;

    /** Bump to invalidate every cached analytics payload after a shape change. */
    public const VERSION = 'v2';

    public const PREFIX = 'analytics';

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $query, Period $period, Filters $filters, Closure $compute, string $extra = ''): mixed
    {
        $shopId = Tenant::id();
        if ($shopId === null) {
            return $compute();
        }

        return Cache::remember(self::key($shopId, $query, $period, $filters, $extra), self::TTL_SECONDS, $compute);
    }

    public static function key(int $shopId, string $query, Period $period, Filters $filters, string $extra = ''): string
    {
        // The locale is part of the key: payloads may carry translated labels.
        return implode(':', [self::PREFIX, self::VERSION, $shopId, app()->getLocale(), $query, $period->key(), $filters->key(), $extra]);
    }
}
