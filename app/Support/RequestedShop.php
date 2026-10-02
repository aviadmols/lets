<?php

namespace App\Support;

use App\Models\Shop;

/**
 * The shop the request's HOST names (`<handle>.app.lets.co.il`), or null on the
 * platform root / any other host. Set by ResolveShopFromHost at the very start
 * of every admin request, cleared at the request's end.
 *
 * This is NOT a tenant binding and never one by itself: Tenant is still bound
 * from the authenticated user (BindTenantFromUser). The requested shop is a
 * WALL on top — a merchant whose shop differs is signed out of this host, and a
 * platform admin is entered into exactly this shop. Nothing in the request body
 * or query can set it; only the Host header, matched against `shops.handle`.
 */
final class RequestedShop
{
    // === CONSTANTS ===
    /** The key a log line carries the requested shop under. */
    public const LOG_KEY = 'requested_shop_id';

    // === STATE ===
    private static ?Shop $shop = null;

    public static function set(Shop $shop): void
    {
        self::$shop = $shop;
    }

    public static function current(): ?Shop
    {
        return self::$shop;
    }

    public static function id(): ?int
    {
        $id = self::$shop?->getKey();

        return $id !== null ? (int) $id : null;
    }

    /** Is this request on a shop host (as opposed to the platform root)? */
    public static function check(): bool
    {
        return self::$shop !== null;
    }

    public static function clear(): void
    {
        self::$shop = null;
    }
}
