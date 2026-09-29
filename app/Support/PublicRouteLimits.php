<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limits for the few PUBLIC routes that do real work on every hit and
 * belong to no domain provider of their own.
 *
 *   - the WooCommerce plugin download zips the whole plugin tree per request —
 *     unthrottled, a loop of GETs is CPU, disk and temp files on the web box;
 *   - the Shopify OAuth install writes a state nonce per request.
 *
 * Both are per client IP and generous for a human: a merchant downloads the
 * plugin once, and installs an app once.
 */
final class PublicRouteLimits
{
    // === CONSTANTS ===
    public const LIMITER_PLUGIN_DOWNLOAD = 'plugin-download';

    public const LIMITER_SHOPIFY_INSTALL = 'shopify-install';

    public const PLUGIN_DOWNLOADS_PER_MINUTE = 6;

    public const SHOPIFY_INSTALLS_PER_MINUTE = 20;

    public static function register(): void
    {
        RateLimiter::for(self::LIMITER_PLUGIN_DOWNLOAD, static fn (Request $request): Limit => Limit::perMinute(self::PLUGIN_DOWNLOADS_PER_MINUTE)
            ->by('ip:'.(string) $request->ip()));

        RateLimiter::for(self::LIMITER_SHOPIFY_INSTALL, static fn (Request $request): Limit => Limit::perMinute(self::SHOPIFY_INSTALLS_PER_MINUTE)
            ->by('ip:'.(string) $request->ip()));
    }
}
