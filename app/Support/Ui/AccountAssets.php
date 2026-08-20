<?php

namespace App\Support\Ui;

/**
 * The personal area's own CSS/JS, addressed so a browser cannot serve a stale
 * copy after a deploy.
 *
 * The storefront never had this problem: the WordPress plugin enqueues its copy
 * against LETS_PAYPLUS_VERSION, so every plugin release is a new URL. The ADMIN
 * PREVIEW loaded the files bare, which meant a merchant could open Settings →
 * Customer area, tune a colour, and be shown a renderer from before the last
 * deploy. A preview exists precisely so that looking right and being right are
 * the same thing; a cached one is worse than none.
 *
 * filemtime, not a random string: the URL changes when the FILE changes and not
 * otherwise, so the asset stays cacheable between deploys. Same idiom as
 * AdminPanelProvider::themeAssetUrl().
 */
final class AccountAssets
{
    // === CONSTANTS ===
    /** Query parameter carrying the cache-buster. */
    private const PARAM = 'v';

    /** A public asset path with a content stamp, or the bare URL when it is missing. */
    public static function url(string $path): string
    {
        $url = asset($path);
        $file = public_path($path);

        return is_file($file) ? $url.'?'.self::PARAM.'='.filemtime($file) : $url;
    }
}
