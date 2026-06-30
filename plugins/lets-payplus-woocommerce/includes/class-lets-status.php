<?php
/**
 * LETS — connection status endpoint.
 *
 * GET /wp-json/lets-payplus/v1/status  (public, read-only)
 *
 * Lets the LETS SaaS verify, from ITS side (the "Test connection" button on the shop
 * page), that this WordPress site has the plugin active and holds the CORRECT connection
 * token. It returns:
 *   - active / plugin_version / wc_active / connected (handshake finished?),
 *   - key_hash: a NON-SECRET fingerprint = sha256(stored api_key). That is exactly the
 *     value the SaaS already keeps as `lets_api_key_hash` for shop lookup, so it can
 *     compare without exposing any secret (the api_secret never leaves the site).
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/status', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $conn = lets_payplus_connection();
            $api_key = is_array($conn) ? (string) ($conn['api_key'] ?? '') : '';

            return array(
                'active'         => true,
                'plugin_version' => LETS_PAYPLUS_VERSION,
                'wc_active'      => class_exists('WooCommerce'),
                'connected'      => (bool) get_option('lets_payplus_wc_webhook_secret'),
                // Non-secret token fingerprint — the SaaS compares it to lets_api_key_hash.
                'key_hash'       => $api_key !== '' ? hash('sha256', $api_key) : null,
            );
        },
    ));
});
