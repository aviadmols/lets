<?php
/**
 * LETS — inbound notifications → activity log + admin email (W16).
 *
 *   POST /wp-json/lets-payplus/v1/notify        SaaS → plugin, HMAC-signed with wc_webhook_secret.
 *                                               e.g. a failed gateway payment PayPlus told LETS about.
 *   POST /wp-json/lets-payplus/v1/iframe-error   browser → plugin, WP-nonce guarded. The embedded
 *                                               PayPlus page failed to load.
 *
 * Both record a row in the merchant activity log and (if enabled) email the WordPress site admin.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('LETS_PAYPLUS_NOTIFY_PATH', '/wp-json/lets-payplus/v1/notify');
define('LETS_PAYPLUS_NOTIFY_SKEW', 300); // seconds of allowed clock skew

add_action('rest_api_init', function () {
    // SaaS → plugin. Public callback, but the HMAC is verified INSIDE against wc_webhook_secret.
    register_rest_route(LETS_PAYPLUS_REST_NS, '/notify', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_notify',
        'permission_callback' => '__return_true',
    ));
    // Browser → plugin. Nonce-guarded (same as the other storefront proxies).
    register_rest_route(LETS_PAYPLUS_REST_NS, '/iframe-error', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_iframe_error',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

/** Signed SaaS notification (payment_failed, …). Verifies the HMAC before acting. */
function lets_payplus_rest_notify(WP_REST_Request $request)
{
    $secret = (string) get_option('lets_payplus_wc_webhook_secret', '');
    if ($secret === '') {
        return new WP_REST_Response(array('error' => 'not_connected'), 400);
    }

    $ts   = (string) $request->get_header('X-LETS-Timestamp');
    $sig  = (string) $request->get_header('X-LETS-Signature');
    $body = (string) $request->get_body();

    if ($ts === '' || $sig === '' || abs(time() - (int) $ts) > LETS_PAYPLUS_NOTIFY_SKEW) {
        return new WP_REST_Response(array('error' => 'unauthorized'), 401);
    }

    // The SaaS (WooPluginNotifier) signs ts + 'POST' + PATH + rawBody with wc_webhook_secret.
    $expected = base64_encode(hash_hmac('sha256', $ts . 'POST' . LETS_PAYPLUS_NOTIFY_PATH . $body, $secret, true));
    if (! hash_equals($expected, $sig)) {
        return new WP_REST_Response(array('error' => 'unauthorized'), 401);
    }

    $data  = json_decode($body, true);
    $event = is_array($data) && isset($data['event']) ? (string) $data['event'] : '';

    // The RETURN LEG of the all-orders invoicing scope: LETS queued the document when
    // we reported the order, and tells us its number + URL once the provider answered.
    if ($event === 'document_issued') {
        return lets_payplus_notify_document_issued(is_array($data) ? $data : array());
    }

    if ($event === 'payment_failed') {
        $order  = isset($data['order_id']) ? (string) $data['order_id'] : '';
        $status = isset($data['status_code']) ? (string) $data['status_code'] : '';
        $reason = isset($data['reason']) ? (string) $data['reason'] : '';
        /* translators: 1: order number, 2: PayPlus status, 3: reason text. */
        $msg = trim(sprintf(__('Payment failed for order %1$s (status %2$s). %3$s', 'lets-payplus'), $order, $status, $reason));
        lets_payplus_log_event($msg, 'payment', 'error');
        lets_payplus_notify_admin(__('A PayPlus payment failed', 'lets-payplus'), $msg);
    }

    return new WP_REST_Response(array('ok' => true), 200);
}

/**
 * LETS issued an accounting document for one of our orders → stamp the order meta
 * (the plugin-side double-issue wall) and add an order note the merchant can see.
 *
 * The HMAC has already been verified by the caller, so the shop is trusted; we still
 * resolve the order ourselves and no-op on anything we cannot find.
 */
function lets_payplus_notify_document_issued(array $data)
{
    $order_id = isset($data['order_id']) ? (int) $data['order_id'] : 0;

    if ($order_id <= 0 || ! function_exists('wc_get_order') || ! function_exists('lets_payplus_invoicing_stamp_order')) {
        // Nothing to stamp (no WooCommerce, or a malformed event). Answer 200 so LETS
        // does not retry a notification that can never succeed here.
        return new WP_REST_Response(array('ok' => true, 'stamped' => false), 200);
    }

    $order = wc_get_order($order_id);
    if ($order instanceof WC_Order) {
        lets_payplus_invoicing_stamp_order($order, array(
            'id'     => isset($data['document_id']) ? (string) $data['document_id'] : '',
            'number' => isset($data['document_number']) ? (string) $data['document_number'] : '',
            'url'    => isset($data['document_url']) ? (string) $data['document_url'] : '',
        ));
    }

    return new WP_REST_Response(array('ok' => true), 200);
}

/** Browser report that the embedded PayPlus iframe failed to load. */
function lets_payplus_rest_iframe_error(WP_REST_Request $request)
{
    $order_id  = (int) $request->get_param('order_id');
    $order_key = (string) $request->get_param('order_key');

    if (function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if (! $order || ($order_key !== '' && ! hash_equals((string) $order->get_order_key(), $order_key))) {
            return new WP_REST_Response(array('error' => 'invalid_order'), 422);
        }
    }

    /* translators: %d: order number. */
    $msg = sprintf(__('The PayPlus payment page (iframe) failed to load for order %d.', 'lets-payplus'), $order_id);
    lets_payplus_log_event($msg, 'iframe', 'error');
    lets_payplus_notify_admin(__('PayPlus payment page failed to load', 'lets-payplus'), $msg);

    return new WP_REST_Response(array('ok' => true), 200);
}
