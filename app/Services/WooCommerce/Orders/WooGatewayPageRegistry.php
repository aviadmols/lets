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
 * Every page opened for the order is kept (a shopper may retry, or pay in an
 * older tab): the page a request names wins when it is one of OURS, else the
 * newest. A cache entry, not a column: it only has to outlive the checkout.
 * When it is missing (expired, cache flushed) the verifier falls back to the
 * marker check.
 */
final class WooGatewayPageRegistry
{
    // === CONSTANTS ===
    public const KEY_PREFIX = 'woo-gateway-page:';

    /** Longer than any checkout, bank 3DS detour, or late PayPlus retry. */
    public const TTL_SECONDS = 1_209_600; // 14 days

    /** Pages kept per order — retries beyond this are not a real checkout. */
    public const MAX_PAGES = 10;

    public static function remember(Shop $shop, string $orderId, string $pageRequestUid): void
    {
        if ($orderId === '' || $pageRequestUid === '') {
            return;
        }

        $pages = array_values(array_diff(self::pagesFor($shop, $orderId), [$pageRequestUid]));
        $pages[] = $pageRequestUid;

        Cache::put(self::key($shop, $orderId), array_slice($pages, -self::MAX_PAGES), self::TTL_SECONDS);
    }

    /**
     * The page to ask PayPlus about: the one the request names when it is one we
     * opened for this order, else the newest we opened, else null.
     */
    public static function pageFor(Shop $shop, string $orderId, string $named = ''): ?string
    {
        $pages = self::pagesFor($shop, $orderId);
        if ($pages === []) {
            return null;
        }

        return $named !== '' && in_array($named, $pages, true) ? $named : end($pages);
    }

    /** @return list<string> oldest first */
    private static function pagesFor(Shop $shop, string $orderId): array
    {
        $stored = $orderId === '' ? null : Cache::get(self::key($shop, $orderId));

        return array_values(array_filter(is_array($stored) ? $stored : [], static fn ($p): bool => is_string($p) && $p !== ''));
    }

    private static function key(Shop $shop, string $orderId): string
    {
        return self::KEY_PREFIX.$shop->getKey().':'.$orderId;
    }
}
