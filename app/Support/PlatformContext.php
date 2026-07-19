<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The platform-admin "Enter / Exit shop" switch. A platform admin (the app owner)
 * has NO single shop; by default BindTenantFromUser leaves them UNBOUND (the
 * BelongsToShop global scope then fails closed → zero rows). To act on a specific
 * shop's behalf they ENTER it: the entered shop id is parked in the session here,
 * and BindTenantFromUser binds exactly that shop for the request — so the global
 * scope still isolates them to ONE shop (entering A never exposes B).
 *
 * This is the ONLY new tenant-binding path W2 adds. It is request/session state,
 * never a constructor of cross-tenant access: a NON platform admin can set the
 * key but the middleware ignores it (only the platform-admin branch reads it), so
 * a merchant can never escape their own shop through this seam.
 *
 * actingActor() feeds the audit trail: every Timeline write performed while a
 * platform admin is entered is attributed to "platform_admin:{userId}".
 */
final class PlatformContext
{
    // === CONSTANTS ===
    /** Session key holding the shop id a platform admin is currently entered into. */
    public const SESSION_KEY = 'platform.entered_shop_id';

    /** Actor prefix written to ActivityEvent.actor while a platform admin is entered. */
    public const ACTOR_PREFIX = 'platform_admin:';

    /** Actor prefix for a plain authenticated user (a merchant / their staff) acting in the admin. */
    public const ADMIN_PREFIX = 'admin:';

    /** Park a shop id in the session (the platform admin is now "entered" into it). */
    public static function enter(int $shopId): void
    {
        Session::put(self::SESSION_KEY, $shopId);
    }

    /** Leave the entered shop → back to platform mode (the Shops list). */
    public static function exit(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /** The shop id the platform admin is entered into, or null in platform mode. */
    public static function enteredShopId(): ?int
    {
        $value = Session::get(self::SESSION_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /** True when there is an entered shop selection in the session. */
    public static function isEntered(): bool
    {
        return self::enteredShopId() !== null;
    }

    /**
     * The actor string for the audit trail, or null when NO authenticated user is acting
     * (the scheduler, queue workers, and HMAC-signed storefront/webhook calls all run with no
     * Auth::user() → callers fall back to system/explicit actor — unchanged).
     *
     *   - a platform admin who has ENTERED a shop → "platform_admin:{id}" (acting on the
     *     merchant's behalf; surfaced distinctly so the merchant sees the app owner touched it);
     *   - any other authenticated user (the merchant or their staff) → "admin:{id}", so an
     *     admin-panel edit is attributed to the exact person who made it (previously "system").
     */
    public static function actingActor(): ?string
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        if (self::isEntered() && method_exists($user, 'isPlatformAdmin') && $user->isPlatformAdmin()) {
            return self::ACTOR_PREFIX . $user->getAuthIdentifier();
        }

        return self::ADMIN_PREFIX . $user->getAuthIdentifier();
    }
}
