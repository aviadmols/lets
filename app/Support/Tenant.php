<?php

namespace App\Support;

use App\Models\Shop;

/**
 * Tenant context holder. The current shop is bound here for the lifetime of a
 * web request or a single queued job (jobs bind it from their explicit shop_id
 * and clear it in finally — workers are long-lived, context must never leak).
 *
 * This is the spine of tenant safety: the BelongsToShop global scope reads
 * Tenant::id(), so a forgotten where() fails closed instead of leaking data.
 */
final class Tenant
{
    // === STATE ===
    private static ?Shop $current = null;

    public static function set(Shop $shop): void
    {
        self::$current = $shop;
    }

    public static function current(): ?Shop
    {
        return self::$current;
    }

    public static function id(): ?int
    {
        return self::$current?->getKey();
    }

    public static function check(): bool
    {
        return self::$current !== null;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    /**
     * Run a callback with a shop bound, restoring the previous context after.
     * Use inside jobs: Tenant::run($shop, fn () => ...).
     */
    public static function run(Shop $shop, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $shop;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
