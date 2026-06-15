<?php

namespace App\Filament\Concerns;

use App\Support\Ui\PanelAccess;

/**
 * Mix into EVERY per-shop Resource/Page (Home, Customers, Products, Payments,
 * Cross-Sell & Upsell, Settings, and their detail pages). It makes the screen
 * appear ONLY when a tenant shop is bound for the request:
 *
 *   - a MERCHANT is always bound to their own shop → unchanged, they see it all;
 *   - a PLATFORM ADMIN is bound ONLY after "Enter shop" → in platform mode the
 *     per-shop screens correctly vanish (no empty/zero-row screens) and a direct
 *     URL is denied (Filament 403s when canAccess() is false), reappearing once
 *     they enter a shop.
 *
 * This is the data-driven seam the W2 spec asks for — the role/entered logic lives
 * here (delegated to PanelAccess::canSeeShopScoped()), not as scattered ternaries.
 *
 * Detail pages that opt out of the sidebar still set
 * `protected static bool $shouldRegisterNavigation = false;`; this trait honours
 * that flag (a hidden page stays hidden) while ALSO requiring a bound tenant.
 */
trait ShopScopedScreen
{
    /** A direct URL hit is denied unless a tenant shop is bound. */
    public static function canAccess(): bool
    {
        return PanelAccess::canSeeShopScoped();
    }

    /** Show in the sidebar only when bound AND the screen hasn't opted out. */
    public static function shouldRegisterNavigation(): bool
    {
        if (! PanelAccess::canSeeShopScoped()) {
            return false;
        }

        // Respect a screen's own opt-out flag (detail pages set this to false).
        return static::$shouldRegisterNavigation;
    }
}
