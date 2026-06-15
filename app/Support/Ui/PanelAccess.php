<?php

namespace App\Support\Ui;

use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * The single, data-driven answer to "who may see this screen?" — used by every
 * Resource/Page's shouldRegisterNavigation()/canAccess() so the role logic is
 * here, not scattered as ad-hoc auth()->user() ternaries across the panel.
 *
 * Two audiences:
 *   - PER-SHOP screens (Home, Customers, Products, Payments, Upsell, Settings):
 *     visible only when a tenant is BOUND. A merchant is always bound to their own
 *     shop; a platform admin is bound ONLY when they have ENTERED a shop. So in
 *     platform mode the per-shop screens correctly disappear (no empty/zero-row
 *     screens) and reappear once entered.
 *   - PLATFORM screens (the Shops/Accounts list): visible ONLY to a platform
 *     admin, in any mode. A merchant gets nothing — nav hidden AND a direct URL
 *     denied (Filament 403s when canAccess() is false).
 *
 * tenantBound() reads Tenant::check() (set by BindTenantFromUser before the panel
 * renders), which is exactly the production seam — there is no second source of
 * truth for "is a shop bound right now".
 */
final class PanelAccess
{
    /** Is the current actor a platform admin (the app owner)? */
    public static function isPlatformAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null
            && method_exists($user, 'isPlatformAdmin')
            && $user->isPlatformAdmin();
    }

    /**
     * Is a tenant shop bound for this request? True for a merchant (their own
     * shop) and for a platform admin who has entered a shop. The gate for every
     * per-shop Resource/Page — keeps platform-mode admins off the empty screens.
     */
    public static function tenantBound(): bool
    {
        return Tenant::check();
    }

    /** May the current actor see the platform-only Shops/Accounts list? */
    public static function canSeePlatform(): bool
    {
        return self::isPlatformAdmin();
    }

    /** May the current actor see the per-shop (merchant) screens right now? */
    public static function canSeeShopScoped(): bool
    {
        return self::tenantBound();
    }
}
