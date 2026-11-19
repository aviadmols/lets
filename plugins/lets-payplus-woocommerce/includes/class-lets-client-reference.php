<?php
/**
 * LETS — the outside system's reference for the buyer, carried through checkout.
 *
 * An app that sells its plans through this store (agent.lets.co.il, say) sends its
 * user here with its OWN id for them on the link:
 *
 *     https://store/checkout/?add-to-cart=123&lets_ref=<the app's reference>
 *
 * The reference is kept in a first-party cookie, written onto the order, and sent
 * to LETS with the payment, where it is stored on the subscription the order
 * starts. Every outbound webhook about that subscription carries it back, so the
 * app knows which of ITS users paid, renewed, failed or cancelled.
 *
 * WHAT IT IS NOT. It is not an identity and nothing here trusts it: anyone can
 * type a link. It is opaque to the store and to LETS. The app that minted it
 * should sign it (an HMAC over its own account id) and check that signature when
 * the webhook hands it back. Here it is only bounded to a safe alphabet.
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The query parameter the app's link carries. */
define('LETS_CLIENT_REF_PARAM', 'lets_ref');

/** The first-party cookie that holds it from the landing page to the payment. */
define('LETS_CLIENT_REF_COOKIE', 'lets_client_ref');

/** Order meta, so the merchant can see on the order which app account it was for. */
define('LETS_CLIENT_REF_META', '_lets_client_ref');

/** How long a reference waits for the shopper to pay. */
define('LETS_CLIENT_REF_TTL', 7 * DAY_IN_SECONDS);

/** The same safe alphabet and length the LETS server accepts. */
define('LETS_CLIENT_REF_PATTERN', '/^[A-Za-z0-9._:~\-]{1,200}\z/');

/** A candidate reference, or null when it is absent or outside the safe alphabet. */
function lets_payplus_clean_client_reference($value)
{
    if (! is_string($value)) {
        return null;
    }

    $value = trim(wp_unslash($value));

    return preg_match(LETS_CLIENT_REF_PATTERN, $value) === 1 ? $value : null;
}

/**
 * Landing with ?lets_ref=… keeps it for the payment. Checked on every front-end
 * request (the app may link to the product page, the cart or straight to
 * checkout); a link without the parameter leaves an earlier reference alone.
 */
add_action('template_redirect', function () {
    if (! isset($_GET[LETS_CLIENT_REF_PARAM])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public landing parameter, no state change beyond a cookie
        return;
    }

    $reference = lets_payplus_clean_client_reference($_GET[LETS_CLIENT_REF_PARAM]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($reference === null || headers_sent()) {
        return;
    }

    setcookie(LETS_CLIENT_REF_COOKIE, $reference, array(
        'expires'  => time() + LETS_CLIENT_REF_TTL,
        'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
        'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    $_COOKIE[LETS_CLIENT_REF_COOKIE] = $reference;
});

/** The reference the shopper arrived with, or null. */
function lets_payplus_client_reference()
{
    return isset($_COOKIE[LETS_CLIENT_REF_COOKIE])
        ? lets_payplus_clean_client_reference($_COOKIE[LETS_CLIENT_REF_COOKIE])
        : null;
}

/**
 * The reference for THIS order: the one already on it, else the shopper's cookie
 * (written onto the order then, so a retried payment carries the same one).
 *
 * @param WC_Order $order
 */
function lets_payplus_order_client_reference($order)
{
    $stored = lets_payplus_clean_client_reference((string) $order->get_meta(LETS_CLIENT_REF_META));
    if ($stored !== null) {
        return $stored;
    }

    $reference = lets_payplus_client_reference();
    if ($reference !== null) {
        $order->update_meta_data(LETS_CLIENT_REF_META, $reference);
        $order->save();

        // Spent: it now lives on this order. Left in the browser, a second
        // buyer on the same computer would inherit the first one's reference.
        lets_payplus_forget_client_reference();
    }

    return $reference;
}

/** Drop the reference cookie (a no-op once the response has started). */
function lets_payplus_forget_client_reference()
{
    unset($_COOKIE[LETS_CLIENT_REF_COOKIE]);

    if (headers_sent()) {
        return;
    }

    setcookie(LETS_CLIENT_REF_COOKIE, '', array(
        'expires'  => time() - HOUR_IN_SECONDS,
        'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
        'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}
