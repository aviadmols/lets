<?php

/**
 * LETS — "log in as this customer".
 *
 * A merchant clicks the action on the LETS customer page and their browser lands
 * here carrying a ticket: `https://the-store.co.il/?lets_login_as=<ticket>`. We
 * hand the ticket back to LETS over the SIGNED channel, LETS answers with the
 * customer it stands for, and WORDPRESS signs that person in.
 *
 * THE TRUST SPLIT IS THE SAME AS THE SIGN-IN CODES, and for the same reason. LETS
 * attests to the ticket; it does not name a WordPress user and it cannot mint a
 * session. This file resolves the address to a user, REFUSES anyone who can edit
 * the site, and only then issues the cookie — through the one helper that also
 * re-associates the cart. A LETS admin (or anyone who ever reads a LETS database)
 * therefore cannot become a WordPress administrator by this route. That guard is
 * the whole point of the file and is not conditional on anything.
 *
 * A REFUSAL SAYS NOTHING. Expired, forged, no such customer, or an account we will
 * not hand over — all of them land on My Account with one generic notice. "No such
 * user" on an endpoint anybody can call is an account-enumeration oracle.
 *
 * THE TOKEN NEVER STAYS IN THE URL. Success and failure both end in a redirect
 * without it, so it does not sit in history, in a referrer, or in an access log
 * that outlives its two minutes.
 */
defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The query parameter the LETS admin's browser arrives with. */
define('LETS_IMPERSONATE_PARAM', 'lets_login_as');

/** The shape a ticket must have before it is worth a round trip. Mirrors ImpersonationTicket. */
define('LETS_IMPERSONATE_PATTERN', '/^[A-Za-z0-9]{32,128}$/');

/** Set on My Account after a refusal — turns into ONE generic notice. */
define('LETS_IMPERSONATE_FAILED_PARAM', 'lets_login_as_failed');

/**
 * User meta marking THIS user's current session as an impersonation.
 *
 * Cleared on logout and on any genuine `wp_login`, so a flag can never outlive the
 * session it describes — the customer signing in themselves tomorrow must not be
 * told they are somebody's impersonation.
 */
define('LETS_IMPERSONATE_META', '_lets_impersonated');

/** The admin-bar node id, and the handle of the stylesheet that paints it. */
define('LETS_IMPERSONATE_NODE', 'lets-impersonation');
define('LETS_IMPERSONATE_STYLE', 'lets-payplus-impersonate');

/* -------------------------------------------------------------------------
 * 1. Redeem the ticket
 * ---------------------------------------------------------------------- */

/**
 * EARLY on `init` — before WooCommerce decides where a logged-out visitor on an
 * account URL belongs, and before anything else can redirect the ticket away.
 */
add_action('init', 'lets_payplus_impersonate_maybe_redeem', 1);

function lets_payplus_impersonate_maybe_redeem()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ticket IS the credential; it is verified by LETS over the signed channel below.
    if (empty($_GET[LETS_IMPERSONATE_PARAM]) || null === lets_payplus_connection()) {
        return;
    }

    // Not on a background request: cron, REST and admin-ajax have no browser to
    // sign in, and issuing a cookie there would be a session nobody asked for.
    if (wp_doing_cron() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
    $ticket = sanitize_text_field(wp_unslash($_GET[LETS_IMPERSONATE_PARAM]));

    if (! preg_match(LETS_IMPERSONATE_PATTERN, $ticket)) {
        lets_payplus_impersonate_refuse('malformed_ticket');
    }

    $result = lets_payplus_signed_post('/api/woocommerce/account/impersonate/verify', array(
        'ticket' => $ticket,
    ));

    if (is_wp_error($result) || empty($result['ok'])) {
        lets_payplus_impersonate_refuse(
            is_wp_error($result) ? $result->get_error_message() : 'ticket_not_valid'
        );
    }

    $email = isset($result['email']) ? sanitize_email((string) $result['email']) : '';
    $user = lets_payplus_impersonate_user($email);

    if (! $user) {
        lets_payplus_impersonate_refuse('no_matching_user');
    }

    // THE GUARD THAT CANNOT BE RELAXED. A LETS admin must never be able to become
    // a WordPress administrator, a shop manager, or anyone else who can edit this
    // site. Checked here as well as inside sign_in() because this is the sentence
    // a future reader must not be able to delete by accident.
    if (lets_payplus_account_is_privileged($user)) {
        lets_payplus_impersonate_refuse('privileged_user_refused');
    }

    // The one helper both passwordless routes use: it sets the auth cookie AND
    // re-associates the cart and the customer session with the now-known shopper.
    $response = lets_payplus_account_sign_in($user, lets_payplus_impersonate_account_url());
    $data = is_object($response) && method_exists($response, 'get_data') ? (array) $response->get_data() : array();

    if (empty($data['ok'])) {
        lets_payplus_impersonate_refuse('sign_in_refused');
    }

    // AFTER the sign-in, never before: sign_in() fires `wp_login`, and this flag's
    // own listener clears it on exactly that hook.
    lets_payplus_impersonate_mark($user->ID);

    lets_payplus_log_event(
        sprintf(
            'A LETS admin signed in as customer #%d (%s).',
            (int) $user->ID,
            isset($result['customer_ref']) ? (string) $result['customer_ref'] : ''
        ),
        'login_as_customer',
        'info'
    );

    // Without the ticket in it — the URL is the last place a spent credential
    // should be able to linger.
    $redirect = ! empty($data['redirect']) ? (string) $data['redirect'] : lets_payplus_impersonate_account_url();
    wp_safe_redirect($redirect);
    exit;
}

/**
 * The WP user behind the attested address, or null.
 *
 * `get_user_by` first, then the account file's resolver — which is what finds a
 * customer whose LOGIN address differs from the one their orders carry.
 *
 * @param  string  $email
 * @return WP_User|null
 */
function lets_payplus_impersonate_user($email)
{
    if ('' === $email || ! is_email($email)) {
        return null;
    }

    $user = get_user_by('email', $email);
    if ($user) {
        return $user;
    }

    return lets_payplus_account_find_user('email', $email);
}

/**
 * Give up, identically for every reason. Logs the real cause for the merchant and
 * shows the visitor one sentence that distinguishes nothing. Never returns.
 *
 * @param  string  $reason  for the activity log only — it never reaches the browser
 */
function lets_payplus_impersonate_refuse($reason)
{
    lets_payplus_log_event(
        'A "log in as customer" link was refused: ' . (string) $reason,
        'login_as_customer',
        'error'
    );

    wp_safe_redirect(add_query_arg(LETS_IMPERSONATE_FAILED_PARAM, '1', lets_payplus_impersonate_account_url()));
    exit;
}

/** My Account, or the home page on a store where WooCommerce has no such page. */
function lets_payplus_impersonate_account_url()
{
    $url = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';

    return '' !== $url ? $url : home_url('/');
}

/**
 * The one sentence a refused link produces. Rendered through WooCommerce's own
 * notices on `template_redirect`, which is after the WC session exists.
 */
add_action('template_redirect', 'lets_payplus_impersonate_failed_notice');

function lets_payplus_impersonate_failed_notice()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag, not an action.
    if (empty($_GET[LETS_IMPERSONATE_FAILED_PARAM]) || ! function_exists('wc_add_notice')) {
        return;
    }

    wc_add_notice(
        lets_payplus_account_is_he() ? 'הקישור אינו תקף יותר.' : 'That link is no longer valid.',
        'error'
    );
}

/* -------------------------------------------------------------------------
 * 2. The way back
 * ---------------------------------------------------------------------- */

/** Mark this user's session as an impersonation. */
function lets_payplus_impersonate_mark($user_id)
{
    update_user_meta((int) $user_id, LETS_IMPERSONATE_META, (string) time());
}

/** Is the CURRENT visitor an impersonated session? */
function lets_payplus_impersonating()
{
    $user_id = get_current_user_id();

    return $user_id > 0 && '' !== (string) get_user_meta($user_id, LETS_IMPERSONATE_META, true);
}

/**
 * The flag dies with the session it describes: on logout, and on any genuine
 * login — including the customer's own, so they are never shown somebody else's
 * banner on their own account.
 */
add_action('wp_logout', 'lets_payplus_impersonate_forget');

function lets_payplus_impersonate_forget($user_id = 0)
{
    $user_id = (int) $user_id;
    if ($user_id > 0) {
        delete_user_meta($user_id, LETS_IMPERSONATE_META);
    }
}

add_action('wp_login', 'lets_payplus_impersonate_forget_on_login', 10, 2);

function lets_payplus_impersonate_forget_on_login($user_login, $user = null)
{
    if ($user instanceof WP_User) {
        lets_payplus_impersonate_forget($user->ID);
    }
}

/**
 * WooCommerce hides the admin bar from customers, which is the right default and
 * exactly wrong here: while a session is an impersonation, the bar IS the way out.
 */
add_filter('show_admin_bar', 'lets_payplus_impersonate_show_admin_bar', 99);

function lets_payplus_impersonate_show_admin_bar($show)
{
    return lets_payplus_impersonating() ? true : $show;
}

add_filter('woocommerce_disable_admin_bar', 'lets_payplus_impersonate_keep_admin_bar', 99);

function lets_payplus_impersonate_keep_admin_bar($disable)
{
    return lets_payplus_impersonating() ? false : $disable;
}

/**
 * "Signed in as {name} — exit". One node, and the node IS the exit: it logs out
 * and returns to wp-admin, where the merchant signs in as themselves again.
 */
add_action('admin_bar_menu', 'lets_payplus_impersonate_admin_bar_node', 90);

function lets_payplus_impersonate_admin_bar_node($bar)
{
    if (! lets_payplus_impersonating()) {
        return;
    }

    $user = wp_get_current_user();
    $name = (string) ($user->display_name ?: $user->user_login);

    $bar->add_node(array(
        'id'    => LETS_IMPERSONATE_NODE,
        'title' => lets_payplus_account_is_he()
            ? 'מחובר כ־' . esc_html($name) . ' — יציאה'
            : 'Signed in as ' . esc_html($name) . ' — exit',
        'href'  => wp_logout_url(admin_url()),
        'meta'  => array(
            'class' => 'lets-impersonation-node',
            'title' => lets_payplus_account_is_he()
                ? 'יציאה מהמצב הזה וחזרה לניהול'
                : 'Leave this session and return to the dashboard',
        ),
    ));
}

/**
 * The node's own stylesheet — a file, never a style attribute. Enqueued only while
 * a session is an impersonation, so an ordinary shopper never downloads it.
 */
add_action('wp_enqueue_scripts', 'lets_payplus_impersonate_styles');
add_action('admin_enqueue_scripts', 'lets_payplus_impersonate_styles');

function lets_payplus_impersonate_styles()
{
    if (! lets_payplus_impersonating()) {
        return;
    }

    wp_enqueue_style(
        LETS_IMPERSONATE_STYLE,
        LETS_PAYPLUS_URL . 'assets/css/lets-impersonate.css',
        array(),
        LETS_PAYPLUS_VERSION
    );
}
