<?php

/**
 * LETS — the LETS management panel, INSIDE wp-admin.
 *
 * The merchant clicks "LETS" in the WordPress menu and the LETS SaaS admin opens
 * right there, already signed in. No second tab, no second password, no "which
 * shop am I looking at" — the shop is the one this store is connected to, because
 * the sign-in is minted over the same signed channel the rest of the plugin uses.
 *
 * How the auto sign-in works: on every render this file asks LETS for a ONE-TIME
 * embed URL, server-side, over lets_payplus_signed_post() (HMAC with the stored
 * api_secret — the browser never signs anything and never sees the secret). LETS
 * returns a URL that is valid ONCE and for 60 seconds. The iframe loads it, LETS
 * sets its own partitioned session cookie, and from that moment the panel
 * navigates on its own; the plugin does nothing further.
 *
 * Which is exactly why nothing here is cached. A minted URL is a live credential
 * with a one-minute life: putting it in a transient, in an option, or in the page
 * for JavaScript to fetch later would leave a spent (or worse, unspent) sign-in
 * lying around. It is minted immediately before the iframe is printed, and never
 * anywhere else. Contrast the members-club URL in class-lets-loyalty.php, which is
 * a shopper-scoped page and IS cached — this one must not be.
 */
if (! defined('ABSPATH')) {
    exit;
}

// === CONSTANTS ===

/** The wp-admin page slug of the embedded panel (the LETS menu's first screen). */
define('LETS_EMBED_SLUG', 'lets-payplus-admin');

/** The wp-admin hook suffix WordPress gives that top-level page. */
define('LETS_EMBED_HOOK', 'toplevel_page_' . LETS_EMBED_SLUG);

/** The SaaS endpoint that mints the single-use, auto-signed-in embed URL. */
define('LETS_EMBED_ENDPOINT', '/api/woocommerce/embed/session');

/** Handle + path of the stylesheet that sizes the frame (loaded on this screen only). */
define('LETS_EMBED_STYLE', 'lets-payplus-admin');
define('LETS_EMBED_STYLE_PATH', 'assets/css/lets-admin.css');

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------

add_action('admin_enqueue_scripts', 'lets_payplus_embed_assets');

/**
 * The frame's sizing lives in a stylesheet, not in a style attribute, and it
 * loads on this one screen — the rest of wp-admin has no use for it.
 *
 * @param  string  $hook_suffix
 * @return void
 */
function lets_payplus_embed_assets($hook_suffix)
{
    if (LETS_EMBED_HOOK !== $hook_suffix) {
        return;
    }

    wp_enqueue_style(
        LETS_EMBED_STYLE,
        LETS_PAYPLUS_URL . LETS_EMBED_STYLE_PATH,
        array(),
        LETS_PAYPLUS_VERSION
    );
}

// ---------------------------------------------------------------------------
// Screen
// ---------------------------------------------------------------------------

/**
 * The embedded panel screen.
 *
 * @return void
 */
function lets_payplus_embed_render()
{
    if (! current_user_can(LETS_ADMIN_CAPABILITY)) {
        return;
    }

    echo '<div class="wrap lets-embed">';
    echo '<h1>LETS</h1>';

    if (null === lets_payplus_connection()) {
        lets_payplus_embed_connect_notice(lets_payplus_admin_text(
            'החנות עדיין לא מחוברת ל-LETS. חברו אותה כדי לפתוח את מערכת הניהול כאן.',
            'This store is not connected to LETS yet. Connect it to open the management panel here.'
        ));
        echo '</div>';

        return;
    }

    $url = lets_payplus_embed_session_url();

    // No URL means no iframe. An <iframe src=""> re-loads the CURRENT admin page
    // inside itself, which looks like the panel came up and then broke; a plain
    // error that names the reason is the honest screen.
    if (is_wp_error($url)) {
        lets_payplus_embed_connect_notice(
            lets_payplus_admin_text(
                'לא הצלחנו לפתוח את מערכת הניהול של LETS: ',
                'Could not open the LETS management panel: '
            ) . $url->get_error_message()
        );
        echo '</div>';

        return;
    }

    printf(
        '<iframe class="lets-embed-frame" src="%s" title="LETS" allow="clipboard-write" referrerpolicy="strict-origin-when-cross-origin"></iframe>',
        esc_url($url)
    );

    echo '</div>';
}

/**
 * One error notice, always with the way out: the connect screen, where a stale or
 * revoked key is re-pasted.
 *
 * @param  string  $message
 * @return void
 */
function lets_payplus_embed_connect_notice($message)
{
    echo '<div class="notice notice-error"><p>'
        . esc_html($message)
        . ' <a href="' . esc_url(admin_url('options-general.php?page=lets-payplus')) . '">'
        . esc_html(lets_payplus_admin_text('למסך החיבור', 'Go to the connect screen'))
        . '</a></p></div>';
}

/**
 * Mint the single-use embed URL for the WordPress user who is looking at the screen.
 *
 * The identity is taken from the WordPress session and sent over the signed
 * channel — the browser cannot choose whose panel it gets, because it never
 * touches this request.
 *
 * @return string|WP_Error  The URL, or the reason there isn't one.
 */
function lets_payplus_embed_session_url()
{
    $user = wp_get_current_user();

    $result = lets_payplus_signed_post(LETS_EMBED_ENDPOINT, array(
        'wp_user_email' => (string) $user->user_email,
        'wp_user_name'  => (string) $user->display_name,
        'wp_user_id'    => (int) $user->ID,
        'locale'        => function_exists('lets_payplus_is_he') && lets_payplus_is_he() ? 'he' : 'en',
        // Where LETS sends the merchant back to inside WordPress.
        'return_url'    => admin_url('admin.php?page=' . LETS_EMBED_SLUG),
    ));

    if (is_wp_error($result)) {
        return $result;
    }

    $url = isset($result['url']) ? (string) $result['url'] : '';

    if ('' === $url) {
        return new WP_Error('lets_embed_no_url', 'unexpected response');
    }

    return $url;
}
