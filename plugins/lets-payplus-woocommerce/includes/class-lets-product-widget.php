<?php
/**
 * LETS — product-page DEPOSIT widget + the nonce-guarded REST proxy (W11 P2).
 *
 * Three auth layers, kept strictly separate:
 *   - browser → plugin  : a WordPress NONCE (wp_create_nonce / check_ajax_referer).
 *   - plugin  → SaaS     : HMAC-SHA256(ts + METHOD + path + body, api_secret), signed
 *                          ENTIRELY server-side. The api_secret never reaches the browser.
 *   - SaaS    → plugin   : the wc_webhook_secret (used elsewhere for callbacks).
 *
 * Flow: the product page renders a "Pay a deposit & reserve it" button + a tiny
 * calculator (deposit %, installments, frequency). The browser posts the chosen knobs +
 * the variation id to the plugin's own REST route (nonce-checked); the plugin SERVER
 * signs the SaaS /installments/quote (live schedule) and /installments/start (creates the
 * awaiting_first_payment plan + the PayPlus hosted page) calls, and returns the PayPlus
 * invoice_url. The browser then redirects to PayPlus. The shopper's browser never holds
 * the api_secret and never talks to the SaaS directly.
 */

if (! defined('ABSPATH')) {
    exit;
}

// === CONSTANTS ===
define('LETS_PAYPLUS_REST_NS', 'lets-payplus/v1');
define('LETS_PAYPLUS_NONCE_ACTION', 'lets_payplus_storefront');

/**
 * Server-side HMAC signer + transport to the SaaS. POST $path (e.g. "/api/woocommerce/
 * installments/quote") with $body; signs with the connection api_secret. Returns the
 * decoded JSON array on 2xx, or a WP_Error. The api_secret lives only here, on the server.
 */
function lets_payplus_signed_post($path, $body)
{
    $conn = lets_payplus_connection();
    if ($conn === null) {
        return new WP_Error('lets_not_connected', 'This store is not connected to LETS.');
    }

    // Derive the SaaS origin from the stored install_url; swap its path for $path.
    $origin = lets_payplus_saas_origin($conn);
    if ($origin === '') {
        return new WP_Error('lets_bad_origin', 'Could not resolve the LETS endpoint.');
    }

    $json = wp_json_encode($body);
    $ts = (string) time();
    $signature = base64_encode(hash_hmac('sha256', $ts . 'POST' . $path . $json, $conn['api_secret'], true));

    $resp = wp_remote_post($origin . $path, array(
        'timeout' => 20,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-LETS-Key' => $conn['api_key'],
            'X-LETS-Timestamp' => $ts,
            'X-LETS-Signature' => $signature,
        ),
        'body' => $json,
    ));

    if (is_wp_error($resp)) {
        return $resp;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $decoded = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) && ! empty($decoded['error']) ? $decoded['error'] : ('HTTP ' . $code);

        // Carry the decoded SaaS body so callers can read business fields (e.g. the upsell `result`)
        // even on a non-2xx — otherwise a 422 collapses to a bare "HTTP 422".
        return new WP_Error('lets_saas_error', $msg, array('status' => $code, 'body' => is_array($decoded) ? $decoded : array()));
    }

    return is_array($decoded) ? $decoded : array();
}

/**
 * Server-side HMAC-signed GET to the SaaS. Signs ts + 'GET' + $path + '' (empty body —
 * the SaaS VerifyWooCommerceSignature signs the path + raw body, NOT the query string), and
 * appends $query to the URL. Returns the decoded JSON array on 2xx, or a WP_Error.
 */
function lets_payplus_signed_get($path, $query)
{
    $conn = lets_payplus_connection();
    if ($conn === null) {
        return new WP_Error('lets_not_connected', 'This store is not connected to LETS.');
    }
    $origin = lets_payplus_saas_origin($conn);
    if ($origin === '') {
        return new WP_Error('lets_bad_origin', 'Could not resolve the LETS endpoint.');
    }

    $ts = (string) time();
    // The body is empty for a GET; the signature covers the PATH only (not the query).
    $signature = base64_encode(hash_hmac('sha256', $ts . 'GET' . $path . '', $conn['api_secret'], true));
    $url = $origin . $path . (($query !== '') ? ('?' . ltrim($query, '?')) : '');

    $resp = wp_remote_get($url, array(
        'timeout' => 20,
        'headers' => array(
            'Accept' => 'application/json',
            'X-LETS-Key' => $conn['api_key'],
            'X-LETS-Timestamp' => $ts,
            'X-LETS-Signature' => $signature,
        ),
    ));

    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $decoded = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) && ! empty($decoded['error']) ? $decoded['error'] : ('HTTP ' . $code);

        // Carry the decoded SaaS body so callers can read business fields (e.g. the upsell `result`)
        // even on a non-2xx — otherwise a 422 collapses to a bare "HTTP 422".
        return new WP_Error('lets_saas_error', $msg, array('status' => $code, 'body' => is_array($decoded) ? $decoded : array()));
    }

    return is_array($decoded) ? $decoded : array();
}

/** The SaaS origin (scheme://host[:port]) derived from the stored install_url. */
function lets_payplus_saas_origin($conn)
{
    $url = isset($conn['install_url']) ? (string) $conn['install_url'] : '';
    $parts = wp_parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (! empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    return $origin;
}

/** Register the nonce-guarded REST proxy routes (browser → plugin). */
add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/installments/quote', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_quote',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    register_rest_route(LETS_PAYPLUS_REST_NS, '/installments/start', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_start',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    register_rest_route(LETS_PAYPLUS_REST_NS, '/installments/subscribe', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_subscribe',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

/** Browser → plugin auth: a valid storefront nonce in the X-WP-Nonce header. */
function lets_payplus_rest_permission(WP_REST_Request $request)
{
    $nonce = (string) $request->get_header('X-WP-Nonce');

    return wp_verify_nonce($nonce, 'wp_rest') ? true : new WP_Error('lets_bad_nonce', 'Invalid nonce.', array('status' => 403));
}

/** POST proxy → SaaS /installments/quote (live schedule preview). */
function lets_payplus_rest_quote(WP_REST_Request $request)
{
    $result = lets_payplus_signed_post('/api/woocommerce/installments/quote', lets_payplus_knobs($request));

    return lets_payplus_rest_response($result);
}

/** POST proxy → SaaS /installments/start (creates the plan + PayPlus page → invoice_url). */
function lets_payplus_rest_start(WP_REST_Request $request)
{
    $result = lets_payplus_signed_post('/api/woocommerce/installments/start', lets_payplus_knobs($request));

    return lets_payplus_rest_response($result);
}

/** POST proxy → SaaS /installments/subscribe (creates a recurring plan + PayPlus page). */
function lets_payplus_rest_subscribe(WP_REST_Request $request)
{
    $result = lets_payplus_signed_post('/api/woocommerce/installments/subscribe', lets_payplus_knobs($request));

    return lets_payplus_rest_response($result);
}

/** Sanitize the calculator knobs + the variation id from the browser request. */
function lets_payplus_knobs(WP_REST_Request $request)
{
    return array(
        'product_id' => (int) $request->get_param('product_id'),
        'variant_id' => (int) $request->get_param('variant_id'),
        'deposit_percent' => (int) $request->get_param('deposit_percent'),
        'installments' => (int) $request->get_param('installments'),
        'frequency' => sanitize_text_field((string) $request->get_param('frequency')),
        'payment_day' => (int) $request->get_param('payment_day'),
    );
}

/** Map a signed-call result (array|WP_Error) onto a REST response. */
function lets_payplus_rest_response($result)
{
    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        $status = (int) ($data['status'] ?? 502);
        // Pass the SaaS body through (so the browser sees `result` etc.), plus the error message.
        $body = (is_array($data) && isset($data['body']) && is_array($data['body'])) ? $data['body'] : array();
        $body['error'] = $result->get_error_message();

        return new WP_REST_Response($body, $status >= 400 ? $status : 502);
    }

    return new WP_REST_Response($result, 200);
}

/**
 * Render the deposit button + calculator below Add-to-Cart on the product page. Only
 * for connected stores (a webhook secret means the connect handshake completed).
 */
add_action('woocommerce_after_add_to_cart_button', function () {
    if (lets_payplus_connection() === null || ! get_option('lets_payplus_wc_webhook_secret')) {
        return;
    }

    global $product;
    if (! is_object($product) || ! method_exists($product, 'get_id')) {
        return;
    }

    $product_id = (int) $product->get_id();
    // For a simple product the variation id IS the product id; variable products set it via JS.
    $variant_id = $product->is_type('variable') ? 0 : $product_id;

    wp_enqueue_style('lets-payplus-storefront');
    wp_enqueue_script('lets-payplus-product-widget');
    wp_localize_script('lets-payplus-product-widget', 'LetsPayPlus', array(
        'restQuote' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/installments/quote')),
        'restStart' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/installments/start')),
        'restSubscribe' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/installments/subscribe')),
        'nonce' => wp_create_nonce('wp_rest'),
        'productId' => $product_id,
        'variantId' => $variant_id,
        'i18n' => array(
            'button' => __('Pay a deposit & reserve it', 'lets-payplus'),
            'title' => __('Pay a deposit, get it reserved', 'lets-payplus'),
            'deposit' => __('Down payment %', 'lets-payplus'),
            'installments' => __('Number of installments', 'lets-payplus'),
            'frequency' => __('Billing frequency', 'lets-payplus'),
            'monthly' => __('Monthly', 'lets-payplus'),
            'weekly' => __('Weekly', 'lets-payplus'),
            'biweekly' => __('Every 2 weeks', 'lets-payplus'),
            'submit' => __('Continue to pay the deposit', 'lets-payplus'),
            'working' => __('Setting things up…', 'lets-payplus'),
            'error' => __('Something went wrong. Please try again.', 'lets-payplus'),
            // Subscribe (recurring) mode.
            'subscribeButton' => __('Subscribe & save', 'lets-payplus'),
            'subscribeTitle' => __('Subscribe & save', 'lets-payplus'),
            'subscribeSublabel' => __('Billed automatically until you cancel', 'lets-payplus'),
            'subscribeSubmit' => __('Continue to subscribe', 'lets-payplus'),
        ),
    ));

    // Two modes side by side: deposit+installments and subscribe (recurring). The script
    // wires both buttons + the shared calculator panel and posts to the matching proxy.
    echo '<div class="lets-pp" data-lets-widget hidden>'
        . '<button type="button" class="lets-pp-open" data-lets-open="deposit">' . esc_html__('Pay a deposit & reserve it', 'lets-payplus') . '</button>'
        . '<button type="button" class="lets-pp-open lets-pp-open--subscribe" data-lets-open="subscribe">' . esc_html__('Subscribe & save', 'lets-payplus') . '</button>'
        . '<div class="lets-pp-panel" data-lets-panel hidden></div>'
        . '</div>';
}, 20);

/** Register (but not enqueue) the widget assets; enqueued on the product page above. */
add_action('wp_enqueue_scripts', function () {
    wp_register_style('lets-payplus-storefront', LETS_PAYPLUS_URL . 'assets/css/lets.css', array(), LETS_PAYPLUS_VERSION);
    wp_register_script('lets-payplus-product-widget', LETS_PAYPLUS_URL . 'assets/js/product-widget.js', array(), LETS_PAYPLUS_VERSION, true);
});
