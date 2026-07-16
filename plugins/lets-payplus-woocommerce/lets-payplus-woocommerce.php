<?php
/**
 * Plugin Name: LETS — PayPlus Subscriptions & Installments for WooCommerce
 * Plugin URI: https://app.lets.co.il
 * Description: Connect your WooCommerce store to LETS to offer PayPlus deposits + installments, recurring subscriptions, one-click post-purchase upsells, and optional full PayPlus checkout. Paste the connection token from your LETS dashboard to link this store.
 * Version: 0.11.0
 * Author: LETS
 * Author URI: https://app.lets.co.il
 * Text Domain: lets-payplus
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * Connect (Phase 1): a Settings → LETS page where the merchant pastes the single
 * connection token from the LETS dashboard (base64url JSON {k,s,u,d}); the plugin server
 * HMAC-signs the install request (the api_secret never leaves the server).
 *
 * Storefront (Phase 2–4): the product-page deposit/subscribe widget, the thank-you
 * post-purchase upsell, and the optional full PayPlus checkout gateway — each browser call
 * goes through a nonce-guarded plugin REST proxy that re-signs the HMAC call to LETS.
 */

if (! defined('ABSPATH')) {
    exit; // never run outside WordPress
}

define('LETS_PAYPLUS_VERSION', '0.11.0');
define('LETS_PAYPLUS_OPT', 'lets_payplus_connection'); // wp_option holding the decoded token
define('LETS_PAYPLUS_FILE', __FILE__);
define('LETS_PAYPLUS_URL', plugin_dir_url(__FILE__)); // base URL for assets

/** Decode the base64url connection token into its parts, or null when invalid. */
function lets_payplus_decode_token($token)
{
    $token = trim((string) $token);
    if ($token === '') {
        return null;
    }
    $b64 = strtr($token, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $json = base64_decode($b64, true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (! is_array($data) || empty($data['k']) || empty($data['s']) || empty($data['u'])) {
        return null;
    }

    return array(
        'api_key' => (string) $data['k'],
        'api_secret' => (string) $data['s'],
        'install_url' => (string) $data['u'],
        'domain' => isset($data['d']) ? (string) $data['d'] : '',
    );
}

/** The stored connection (decoded token), or null when not connected. */
function lets_payplus_connection()
{
    $conn = get_option(LETS_PAYPLUS_OPT, null);

    return is_array($conn) && ! empty($conn['api_key']) ? $conn : null;
}

/** Admin menu: Settings → LETS. */
add_action('admin_menu', function () {
    add_options_page(
        'LETS — PayPlus',
        'LETS',
        'manage_options',
        'lets-payplus',
        'lets_payplus_render_settings'
    );
});

/** Handle the connect form post. */
add_action('admin_post_lets_payplus_connect', function () {
    if (! current_user_can('manage_options')) {
        wp_die('Forbidden', 403);
    }
    check_admin_referer('lets_payplus_connect');

    $decoded = lets_payplus_decode_token(isset($_POST['lets_token']) ? wp_unslash($_POST['lets_token']) : '');
    if ($decoded === null) {
        update_option('lets_payplus_last_error', 'Invalid connection token. Copy it again from the LETS dashboard.');
        wp_safe_redirect(admin_url('options-general.php?page=lets-payplus&status=invalid'));
        exit;
    }

    update_option(LETS_PAYPLUS_OPT, $decoded);
    delete_option('lets_payplus_last_error');

    $result = lets_payplus_call_install($decoded);
    $status = is_wp_error($result) ? 'error' : 'connected';
    if (is_wp_error($result)) {
        update_option('lets_payplus_last_error', $result->get_error_message());
    } else {
        delete_option('lets_payplus_last_error');
        if (! empty($result['wc_webhook_secret'])) {
            update_option('lets_payplus_wc_webhook_secret', (string) $result['wc_webhook_secret']);
        }
    }

    wp_safe_redirect(admin_url('options-general.php?page=lets-payplus&status=' . $status));
    exit;
});

/**
 * Ensure a WooCommerce REST API key exists for LETS to read products/orders, and
 * return its {consumer_key, consumer_secret}. Generated once + cached in an option.
 * Returns null when WooCommerce is not active (the store still links; product sync
 * activates once WooCommerce is present and the merchant reconnects).
 */
function lets_payplus_ensure_wc_keys()
{
    $stored = get_option('lets_payplus_wc_keys', null);
    if (is_array($stored) && ! empty($stored['consumer_key'])) {
        return $stored;
    }
    if (! function_exists('wc_rand_hash') || ! function_exists('wc_api_hash')) {
        return null; // WooCommerce inactive
    }

    global $wpdb;
    $consumer_key = 'ck_' . wc_rand_hash();
    $consumer_secret = 'cs_' . wc_rand_hash();
    $wpdb->insert(
        $wpdb->prefix . 'woocommerce_api_keys',
        array(
            'user_id' => get_current_user_id(),
            'description' => 'LETS — PayPlus',
            // READ/WRITE is REQUIRED: LETS must WRITE to mark orders paid (set_paid) after a
            // PayPlus charge. A read-only key reads products fine but 401s on the paid update,
            // so the order stays "pending" even though the card was charged.
            'permissions' => 'read_write',
            'consumer_key' => wc_api_hash($consumer_key), // WC stores the key hashed
            'consumer_secret' => $consumer_secret,        // and the secret in the clear
            'truncated_key' => substr($consumer_key, -7),
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s')
    );

    $keys = array('consumer_key' => $consumer_key, 'consumer_secret' => $consumer_secret);
    update_option('lets_payplus_wc_keys', $keys);

    return $keys;
}

/**
 * One-time upgrade: promote an EXISTING LETS WooCommerce API key from read-only to read_write.
 * Early installs (≤ v0.9.1) minted a read-only key, so LETS could read products but 401'd when
 * marking an order paid — the card was charged yet the order stayed "pending". This repairs the
 * stored key in place on the next admin page load, so the merchant only has to update the plugin
 * (no WooCommerce key juggling). Idempotent + gated by an option so it runs at most once.
 */
add_action('admin_init', function () {
    if ('1' === get_option('lets_payplus_keys_rw_fixed', '0')) {
        return;
    }

    $stored = get_option('lets_payplus_wc_keys', null);
    if (! is_array($stored) || empty($stored['consumer_key'])) {
        return; // nothing minted yet — the new key will already be read_write
    }

    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_api_keys';
    // Match the exact active key by its truncated tail (how WC identifies it in the list too).
    $wpdb->update(
        $table,
        array('permissions' => 'read_write'),
        array('truncated_key' => substr((string) $stored['consumer_key'], -7)),
        array('%s'),
        array('%s')
    );

    update_option('lets_payplus_keys_rw_fixed', '1');
});

/**
 * Call the LETS install endpoint, HMAC-signed with the connection api_secret.
 * Returns the decoded JSON body on success, or a WP_Error.
 */
function lets_payplus_call_install($conn)
{
    $keys = lets_payplus_ensure_wc_keys();
    $body = wp_json_encode(array(
        'base_url' => home_url(),
        'plugin_version' => LETS_PAYPLUS_VERSION,
        'wp_version' => get_bloginfo('version'),
        'wc_version' => defined('WC_VERSION') ? WC_VERSION : null,
        'consumer_key' => is_array($keys) ? ($keys['consumer_key'] ?? null) : null,
        'consumer_secret' => is_array($keys) ? ($keys['consumer_secret'] ?? null) : null,
    ));

    $url = $conn['install_url'];
    $path = wp_parse_url($url, PHP_URL_PATH);
    $ts = (string) time();
    $signature = base64_encode(hash_hmac('sha256', $ts . 'POST' . $path . $body, $conn['api_secret'], true));

    $resp = wp_remote_post($url, array(
        'timeout' => 20,
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-LETS-Key' => $conn['api_key'],
            'X-LETS-Timestamp' => $ts,
            'X-LETS-Signature' => $signature,
        ),
        'body' => $body,
    ));

    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($json) && ! empty($json['error']) ? $json['error'] : ('HTTP ' . $code);

        return new WP_Error('lets_install_failed', 'LETS connection failed: ' . $msg);
    }

    return is_array($json) ? $json : array();
}

/** Render the Settings → LETS page. */
function lets_payplus_render_settings()
{
    $conn = lets_payplus_connection();
    $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
    $error = get_option('lets_payplus_last_error', '');
    ?>
    <div class="wrap">
        <h1>LETS — PayPlus Subscriptions &amp; Installments</h1>

        <?php if ($status === 'connected') : ?>
            <div class="notice notice-success"><p>Connected to LETS.</p></div>
        <?php elseif ($status === 'invalid') : ?>
            <div class="notice notice-error"><p>That connection token wasn’t valid. Copy it again from your LETS dashboard.</p></div>
        <?php elseif ($status === 'error' && $error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if ($conn) : ?>
            <p><strong>Status:</strong>
                <?php echo get_option('lets_payplus_wc_webhook_secret') ? '✅ Connected' : '🟡 Token saved — activation pending'; ?>
            </p>
            <p><strong>Store:</strong> <?php echo esc_html($conn['domain'] ?: home_url()); ?></p>
        <?php else : ?>
            <p>Paste the <strong>connection token</strong> from your LETS dashboard
               (Shops → your store → Add WooCommerce store) to link this store.</p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('lets_payplus_connect'); ?>
            <input type="hidden" name="action" value="lets_payplus_connect">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="lets_token">Connection token</label></th>
                    <td>
                        <textarea name="lets_token" id="lets_token" rows="4" class="large-text code"
                                  placeholder="Paste the connection token here"></textarea>
                        <p class="description">Shown once in your LETS dashboard. Keep it secret.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button($conn ? 'Reconnect' : 'Connect to LETS'); ?>
        </form>

        <?php
        // Merchant self-service: Test connection · Test payment page · Recent errors.
        if (function_exists('lets_payplus_render_diagnostics')) {
            lets_payplus_render_diagnostics();
        }
        // PayPlus payment-page options (language, installments, methods, receipts, create_token…).
        if (function_exists('lets_payplus_render_page_settings')) {
            lets_payplus_render_page_settings();
        }
        ?>
    </div>
    <?php
}

// Storefront: the product-page deposit/subscribe widget + the nonce-guarded REST proxy
// + the shared HMAC signer (W11 P2/P3). The thank-you upsell + gateway reuse the signer,
// so this MUST load first.
require_once __DIR__ . '/includes/class-lets-product-widget.php';

// Storefront: the thank-you-page post-purchase upsell (W11 P4).
require_once __DIR__ . '/includes/class-lets-thankyou.php';

// Cart-based subscription products (W17 B). Loads AFTER the signer (its REST proxy + product-page
// choice + cart hooks reuse lets_payplus_signed_post / lets_payplus_rest_permission) and BEFORE
// the gateway (which reads the `_lets_subscription` line meta this file stamps).
require_once __DIR__ . '/includes/class-lets-subscriptions.php';

// Optional full PayPlus gateway for normal checkout ("mode B", W11 P4).
require_once __DIR__ . '/includes/class-lets-gateway.php';

// Read-only status endpoint so the SaaS "Test connection" button can confirm the plugin
// is installed with the correct token (GET /wp-json/lets-payplus/v1/status).
require_once __DIR__ . '/includes/class-lets-status.php';

// Merchant diagnostics on Settings → LETS: Test connection · Test payment page · error log.
// Loads AFTER the signer (class-lets-product-widget) whose lets_payplus_signed_post() it uses.
require_once __DIR__ . '/includes/class-lets-diagnostics.php';

// Inbound notifications (SaaS-signed /notify + browser /iframe-error) → activity log + admin email.
require_once __DIR__ . '/includes/class-lets-notify.php';

// PayPlus payment-page options on Settings → LETS (edited here, stored in LETS). Loads AFTER
// diagnostics: reuses its guard/redirect/notice helpers, and the signer for the settings API.
require_once __DIR__ . '/includes/class-lets-page-settings.php';
