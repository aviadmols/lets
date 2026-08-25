<?php

namespace App\Domain\Customers;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The proof LETS hands a WooCommerce store that "this browser may be signed in
 * as this customer" — and nothing more.
 *
 * Two callers, one mechanism. MODE_ADMIN is "log in as this customer": a LETS
 * admin clicks the action and their browser carries the ticket to the store.
 * MODE_CUSTOMER is the passwordless link in a campaign email: the customer
 * themself redeemed the emailed token on our landing page, and this ticket is
 * the short second hop that turns that into a WordPress session. The plugin
 * reads the mode and treats the two differently (no impersonation banner for a
 * customer signing into their own account).
 *
 * In BOTH modes the plugin hands the ticket back over the signed channel, we
 * answer with the customer it stands for, and WORDPRESS decides which of ITS
 * users that is and issues the session — the same trust split as the sign-in
 * codes, and the reason a leaked LETS ticket cannot become a WordPress
 * administrator.
 *
 * WHAT IS STORED IS THE HASH. The ticket travels in a URL (history, referrers,
 * proxy logs) and the payload names a real person, so a cache dump must not be
 * replayable: the key is sha256(token) and the token itself is never written down.
 *
 * SINGLE USE. Cache::pull() removes the payload as it reads it, so the second
 * redemption of a ticket — a refresh, a back button, somebody else's copy of the
 * link — finds nothing.
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

    /** A LETS admin becoming the customer (the store shows a banner + exit). */
    public const MODE_ADMIN = 'admin';

    /** The customer entering their own account from an emailed link. */
    public const MODE_CUSTOMER = 'customer';

    public const MODES = [self::MODE_ADMIN, self::MODE_CUSTOMER];

    /**
     * Seconds an ADMIN ticket stays redeemable.
     *
     * Five minutes, not one: the merchant is HANDED this link rather than
     * followed through it. They open it in a new tab — or copy it into a private
     * window, which is the only way to hold an admin session and a customer
     * session at once — and either can take a moment. Still single use, still
     * short enough that a link left in a chat window is spent long before
     * anybody reads it.
     */
    public const TTL_SECONDS = 300;

    /**
     * Seconds a CUSTOMER ticket stays redeemable. This one IS followed through —
     * our landing page redirects the browser straight to the store — so two
     * minutes is a redirect plus a slow round trip, and nothing more.
     */
    public const TTL_SECONDS_CUSTOMER = 120;

    /** 48 random alphanumeric characters (Str::random's alphabet). */
    public const TOKEN_LENGTH = 48;

    /** The shape the plugin also enforces before it spends a round trip on one. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{32,128}$/';

    /** A redirect path longer than this is not a path anybody typed. */
    public const MAX_REDIRECT = 200;

    /**
     * Mint a ticket for one customer of one shop. Returns the token to put in the
     * store URL; the caller never sees the stored key.
     *
     * `$redirect` is a RELATIVE path on the store (never a URL — a scheme or a
     * leading `//` is refused here and again by the plugin's wp_validate_redirect).
     * `$displayName` lets the plugin create the WordPress account for a customer
     * it has never seen, when the store allows quick registration.
     */
    public static function issue(
        Shop $shop,
        string $customerRef,
        string $email,
        string $mode = self::MODE_ADMIN,
        ?string $redirect = null,
        ?string $displayName = null,
    ): string {
        $mode = in_array($mode, self::MODES, true) ? $mode : self::MODE_ADMIN;
        $token = Str::random(self::TOKEN_LENGTH);

        Cache::put(self::key($token), [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $customerRef,
            'email' => $email,
            'mode' => $mode,
            'redirect' => self::cleanRedirect($redirect),
            'display_name' => $displayName !== null ? mb_substr(trim($displayName), 0, 255) : null,
            'issued_by_user_id' => (int) (auth()->id() ?? 0),
        ], $mode === self::MODE_CUSTOMER ? self::TTL_SECONDS_CUSTOMER : self::TTL_SECONDS);

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
     * @return array{customer_ref: string, email: string, mode: string, redirect: ?string, display_name: ?string}|null
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

        $mode = (string) ($payload['mode'] ?? self::MODE_ADMIN);

        return [
            'customer_ref' => (string) ($payload['customer_ref'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'mode' => in_array($mode, self::MODES, true) ? $mode : self::MODE_ADMIN,
            'redirect' => self::cleanRedirect($payload['redirect'] ?? null),
            'display_name' => isset($payload['display_name']) && $payload['display_name'] !== ''
                ? (string) $payload['display_name']
                : null,
        ];
    }

    /**
     * A store-relative path, or null. Refuses anything that could leave the
     * store: a scheme, a protocol-relative `//`, a backslash trick, control
     * characters. The plugin validates again with wp_validate_redirect.
     */
    public static function cleanRedirect(mixed $redirect): ?string
    {
        $redirect = is_string($redirect) ? trim($redirect) : '';
        if ($redirect === '') {
            return null;
        }

        // BEFORE trimming the leading slash, not after: `//evil.example.com` is a
        // protocol-relative URL, and ltrim()ing both slashes turns it into a bare
        // host that then looks exactly like a path. That is how an open redirect
        // gets through a check that reads correct.
        if (str_starts_with($redirect, '//')
            || str_starts_with($redirect, '\\')
            || str_starts_with($redirect, '/\\')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $redirect) === 1
            || preg_match('/[\x00-\x1F\x7F\s]/', $redirect) === 1) {
            return null;
        }

        $redirect = ltrim($redirect, '/');

        if ($redirect === '' || mb_strlen($redirect) > self::MAX_REDIRECT) {
            return null;
        }

        return $redirect;
    }

    private static function key(string $token): string
    {
        return self::CACHE_PREFIX.hash('sha256', $token);
    }
}
