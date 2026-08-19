<?php

namespace App\Domain\Customers;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Log in as this customer" — the proof LETS hands the store, and nothing more.
 *
 * A LETS admin clicks the action; we mint an opaque ticket and send their browser
 * to the merchant's WordPress site carrying it. The plugin hands the ticket back
 * over the signed channel and we answer with the customer it stands for. WORDPRESS
 * then decides which of ITS users that is and issues the session — the same trust
 * split as the sign-in codes, and the reason a leaked LETS ticket cannot become a
 * WordPress administrator.
 *
 * WHAT IS STORED IS THE HASH. The ticket travels in a URL (history, referrers,
 * proxy logs) and the payload names a real person, so a cache dump must not be
 * replayable: the key is sha256(token) and the token itself is never written down.
 *
 * SINGLE USE, TWO MINUTES. Cache::pull() removes the payload as it reads it, so
 * the second redemption of a ticket — a refresh, a back button, somebody else's
 * copy of the link — finds nothing. Two minutes is a browser redirect plus one
 * round trip, and short enough that a link left in a history entry is worthless.
 *
 * THE SHOP IS PART OF THE PAYLOAD, AND IT IS CHECKED. verify() is given the
 * HMAC-verified shop of the caller; a ticket minted for shop A never resolves for
 * shop B, even though both plugins speak to the same endpoint.
 */
final class ImpersonationTicket
{
    // === CONSTANTS ===
    /** Cache key namespace. The key is the PREFIX + sha256(token), never the token. */
    public const CACHE_PREFIX = 'impersonate:woocommerce:';

    /**
     * Seconds a minted ticket stays redeemable.
     *
     * Five minutes, not one: the merchant is HANDED this link rather than
     * followed through it. They open it in a new tab — or copy it into a private
     * window, which is the only way to hold an admin session and a customer
     * session at once — and either can take a moment. Still single use, still
     * short enough that a link left in a chat window is spent long before
     * anybody reads it.
     */
    public const TTL_SECONDS = 300;

    /** 48 random alphanumeric characters (Str::random's alphabet). */
    public const TOKEN_LENGTH = 48;

    /** The shape the plugin also enforces before it spends a round trip on one. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{32,128}$/';

    /**
     * Mint a ticket for one customer of one shop. Returns the token to put in the
     * store URL; the caller never sees the stored key.
     */
    public static function issue(Shop $shop, string $customerRef, string $email): string
    {
        $token = Str::random(self::TOKEN_LENGTH);

        Cache::put(self::key($token), [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $customerRef,
            'email' => $email,
            'issued_by_user_id' => (int) (auth()->id() ?? 0),
        ], self::TTL_SECONDS);

        return $token;
    }

    /**
     * Spend a ticket for THIS shop, or null.
     *
     * The payload is pulled — and therefore destroyed — before the shop is
     * compared, deliberately: a ticket presented to the wrong store is a ticket
     * that has been somewhere it should not have been, and burning it is the
     * fail-closed answer.
     *
     * @return array{customer_ref: string, email: string}|null
     */
    public static function verify(Shop $shop, string $token): ?array
    {
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $payload = Cache::pull(self::key($token));
        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['shop_id'] ?? 0) !== (int) $shop->getKey()) {
            return null;
        }

        return [
            'customer_ref' => (string) ($payload['customer_ref'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
        ];
    }

    private static function key(string $token): string
    {
        return self::CACHE_PREFIX.hash('sha256', $token);
    }
}
