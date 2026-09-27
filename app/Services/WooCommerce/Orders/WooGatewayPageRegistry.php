<?php

namespace App\Services\WooCommerce\Orders;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * Which PayPlus payment page WE opened for a WooCommerce order.
 *
 * The gateway callback and the verify-on-return both name a page, but that name
 * arrives from outside. Remembering the page id at creation time lets
 * PayPlusCallbackVerifier ask PayPlus about OUR page for the order — so binding
 * a payment to its order does not depend on PayPlus echoing our `more_info`
 * marker back in its lookup response (a shape not yet confirmed live).
 *
 * A cache entry, not a column: it only has to outlive the checkout. When it is
 * missing (expired, cache flushed) the verifier falls back to the marker check.
 */
final class WooGatewayPageRegistry
{
    // === CONSTANTS ===
    public const KEY_PREFIX = 'woo-gateway-page:';

    /** Longer than any checkout, bank 3DS detour, or late PayPlus retry. */
    public const TTL_SECONDS = 1_209_600; // 14 days

    public static function remember(Shop $shop, string $orderId, string $pageRequestUid): void
    {
        if ($orderId === '' || $pageRequestUid === '') {
            return;
        }

        Cache::put(self::key($shop, $orderId), $pageRequestUid, self::TTL_SECONDS);
    }

    public static function pageFor(Shop $shop, string $orderId): ?string
    {
        $uid = $orderId === '' ? null : Cache::get(self::key($shop, $orderId));

        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    private static function key(Shop $shop, string $orderId): string
    {
        return self::KEY_PREFIX.$shop->getKey().':'.$orderId;
    }
}
