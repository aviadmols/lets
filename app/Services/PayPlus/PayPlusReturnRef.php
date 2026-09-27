<?php

namespace App\Services\PayPlus;

use App\Models\Shop;

/**
 * The shop reference a PayPlus RETURN URL carries — the address the shopper's
 * browser is sent to after a hosted page (refURL_success / _failure / _cancel).
 *
 * Those URLs used to carry the shop's CALLBACK token, which is the only thing
 * that routes a server-to-server payment callback to a shop. A URL a browser
 * sees is not a secret: it sits in the address bar, the history and Referer
 * headers. The landing pages need the shop for one harmless thing — the "back
 * to the store" link — so they get their own reference: the shop id plus a
 * keyed MAC over it. It resolves nothing but that page, cannot be turned into a
 * callback URL, and cannot be enumerated across shops.
 */
final class PayPlusReturnRef
{
    // === CONSTANTS ===
    /** Domain separation: this MAC means "a return landing", nothing else. */
    private const PURPOSE = 'payplus-return:';

    private const SEPARATOR = '.';

    /** Hex characters of the MAC kept in the URL (96 bits). */
    private const MAC_LENGTH = 24;

    public static function for(Shop $shop): string
    {
        $id = (string) $shop->getKey();

        return $id.self::SEPARATOR.self::mac($id);
    }

    /** The shop this reference names, or null for anything we did not mint. */
    public static function resolve(string $ref): ?Shop
    {
        $parts = explode(self::SEPARATOR, $ref, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! hash_equals(self::mac($parts[0]), $parts[1])) {
            return null;
        }

        return Shop::query()->find((int) $parts[0]);
    }

    private static function mac(string $id): string
    {
        return substr(hash_hmac('sha256', self::PURPOSE.$id, (string) config('app.key')), 0, self::MAC_LENGTH);
    }
}
