<?php
/**
 * LETS — thank-you-page post-purchase UPSELL (W11 P4).
 *
 * On the WC `woocommerce_thankyou` page we render a one-click "Add to my order" offer:
 *   browser → plugin REST (nonce) → plugin SERVER signs HMAC → SaaS /upsell/offer,
 *   then accept → SaaS /upsell/accept (charges the saved PayPlus token, consent-gated,
 *   idempotent). The api_secret never reaches the browser. The order facts (parent order
 *   id, customer ref, purchased product ids, subtotal) are read from the WC order on the
 *   SERVER, never trusted from the browser.
 *
 * Depends on the shared signer lets_payplus_signed_post() + nonce permission callback in
 * class-lets-product-widget.php (loaded first by the main plugin file).
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Register the thank-you upsell REST proxy routes (browser → plugin). */
add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/upsell/offer', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_upsell_offer',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    register_rest_route(LETS_PAYPLUS_REST_NS, '/upsell/accept', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_upsell_accept',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    register_rest_route(LETS_PAYPLUS_REST_NS, '/upsell/decline', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_upsell_decline',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

/**
 * Resolve the order facts from a WC order id (SERVER-side — the browser only sends the
 * order id + key, which we validate against the order). Returns null when the order can't
 * be loaded/validated.
 */
function lets_payplus_thankyou_facts($order_id, $order_key)
{
    if (! function_exists('wc_get_order')) {
        return null;
    }
    $order = wc_get_order((int) $order_id);
    if (! $order || ($order_key !== '' && ! hash_equals((string) $order->get_order_key(), (string) $order_key))) {
        return null;
    }

    $product_ids = array();
    $collections = array(); // WooCommerce product categories (slugs) — the "collection" analogue
    $tags = array();        // WooCommerce product tags (slugs)
    foreach ($order->get_items() as $item) {
        if (! method_exists($item, 'get_product_id')) {
            continue;
        }
        $pid = (int) $item->get_product_id();
        if ($pid <= 0) {
            continue;
        }
        $product_ids[] = $pid;

        // So collection/tag triggers can fire on WooCommerce. Slugs are stable + human-set,
        // which is what a merchant would type into a tag/collection trigger.
        if (function_exists('wp_get_post_terms')) {
            $cat_slugs = wp_get_post_terms($pid, 'product_cat', array('fields' => 'slugs'));
            $tag_slugs = wp_get_post_terms($pid, 'product_tag', array('fields' => 'slugs'));
            if (is_array($cat_slugs)) {
                $collections = array_merge($collections, $cat_slugs);
            }
            if (is_array($tag_slugs)) {
                $tags = array_merge($tags, $tag_slugs);
            }
        }
    }

    return array(
        'parent_order' => (string) $order->get_id(),
        'customer' => (string) ($order->get_customer_id() ?: $order->get_billing_email()),
        'subtotal' => (string) $order->get_subtotal(),
        'products' => implode(',', array_filter($product_ids)),
        'collections' => implode(',', array_unique(array_filter($collections))),
        'tags' => implode(',', array_unique(array_filter($tags))),
        'email' => (string) $order->get_billing_email(),
    );
}

/** POST proxy → SaaS GET /upsell/offer (resolve the eligible offer for this order). */
function lets_payplus_rest_upsell_offer(WP_REST_Request $request)
{
    $facts = lets_payplus_thankyou_facts($request->get_param('order_id'), (string) $request->get_param('order_key'));
    if ($facts === null) {
        return new WP_REST_Response(array('offer' => null), 200);
    }

    // The SaaS offer endpoint is a GET (read) — sign with the query string as the path.
    $query = http_build_query($facts);
    $result = lets_payplus_signed_get('/api/woocommerce/upsell/offer', $query);

    return lets_payplus_rest_response($result);
}

/** POST proxy → SaaS POST /upsell/accept (charge the saved token + record the child order). */
function lets_payplus_rest_upsell_accept(WP_REST_Request $request)
{
    $facts = lets_payplus_thankyou_facts($request->get_param('order_id'), (string) $request->get_param('order_key'));
    if ($facts === null) {
        return new WP_REST_Response(array('error' => 'invalid_order'), 422);
    }

    $body = array(
        'flow_id' => (int) $request->get_param('flow_id'),
        'offer_id' => (int) $request->get_param('offer_id'),
        'parent_order' => $facts['parent_order'],
        'customer' => $facts['customer'],
        'email' => $facts['email'],
    );

    $result = lets_payplus_signed_post('/api/woocommerce/upsell/accept', $body);

    return lets_payplus_rest_response($result);
}

/** POST proxy → SaaS POST /upsell/decline (record the DECLINED funnel event; no charge). */
function lets_payplus_rest_upsell_decline(WP_REST_Request $request)
{
    $facts = lets_payplus_thankyou_facts($request->get_param('order_id'), (string) $request->get_param('order_key'));
    if ($facts === null) {
        return new WP_REST_Response(array('error' => 'invalid_order'), 422);
    }

    $body = array(
        'flow_id' => (int) $request->get_param('flow_id'),
        'offer_id' => (int) $request->get_param('offer_id'),
        'parent_order' => $facts['parent_order'],
        'customer' => $facts['customer'],
        'email' => $facts['email'],
    );

    $result = lets_payplus_signed_post('/api/woocommerce/upsell/decline', $body);

    return lets_payplus_rest_response($result);
}

/**
 * VERIFY-ON-RETURN: confirm a still-pending gateway payment directly with PayPlus, so the order is
 * marked paid + the card vaulted even when PayPlus never pushed refURL_callback (which left orders
 * stuck "pending"). Best-effort + server-side; a failure never disrupts the page.
 *
 * Runs on `template_redirect` (W18) — BEFORE the order-received template renders — so the order is
 * paid before WooCommerce decides on the "Pay/Cancel" order action. On the older `woocommerce_thankyou`
 * hook it fired AFTER the status/Pay button was already printed, so the button lingered for one
 * pageview. On a successful confirmation we also clear the order cache so the template loads the
 * fresh paid status in THIS request.
 */
function lets_payplus_verify_order($order)
{
    if (! is_object($order) || ! method_exists($order, 'get_payment_method')) {
        return;
    }
    if ($order->get_payment_method() !== 'lets_payplus' || ! $order->needs_payment()) {
        return; // not our gateway order, or already paid
    }
    $uid = (string) $order->get_meta('_lets_payplus_page_request_uid');
    if ($uid === '') {
        return;
    }

    // NOTE: this is OUR endpoint's param name (page_request_uid); the SaaS translates it to PayPlus's
    // payment_request_uid when it calls PayPlus's IPN (W18).
    $result = lets_payplus_signed_post('/api/woocommerce/gateway/verify', array(
        'order_id' => (string) $order->get_id(),
        'page_request_uid' => $uid,
    ));

    if (is_wp_error($result)) {
        if (function_exists('lets_payplus_log_error')) {
            lets_payplus_log_error($result->get_error_message(), 'verify');
        }

        return;
    }

    // Confirmed paid → drop the stale order cache so the thank-you template re-reads the paid status
    // this request (no lingering "Pay" button), regardless of the object-cache backend.
    if (! empty($result['paid'])) {
        if (function_exists('clean_post_cache')) {
            clean_post_cache($order->get_id());
        }
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients($order->get_id());
        }
    }
}

add_action('template_redirect', function () {
    if (lets_payplus_connection() === null || ! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url('order-received')) {
        return;
    }
    $order_id = absint(get_query_var('order-received'));
    if (! $order_id || ! function_exists('wc_get_order')) {
        return;
    }
    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }
    // Validate the order key from the URL (the same guard WooCommerce uses for this page).
    $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
    if ($key !== '' && ! hash_equals((string) $order->get_order_key(), $key)) {
        return;
    }

    lets_payplus_verify_order($order);
});

/** Render the thank-you upsell mount + enqueue the widget on the order-received page. */
add_action('woocommerce_thankyou', function ($order_id) {
    if (lets_payplus_connection() === null || ! get_option('lets_payplus_wc_webhook_secret')) {
        return;
    }
    $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
    if (! $order) {
        return;
    }

    wp_enqueue_style('lets-payplus-storefront');
    wp_enqueue_style('lets-payplus-ppu');   // the shared card stylesheet (+ Heebo dep)
    wp_enqueue_script('lets-payplus-thankyou'); // pulls the shared renderer (dep)
    wp_localize_script('lets-payplus-thankyou', 'LetsPayPlusUpsell', array(
        'restOffer' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/upsell/offer')),
        'restAccept' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/upsell/accept')),
        'restDecline' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/upsell/decline')),
        'nonce' => wp_create_nonce('wp_rest'),
        'orderId' => (int) $order_id,
        'orderKey' => (string) $order->get_order_key(),
        'i18n' => array(
            'add' => __('Add to my order', 'lets-payplus'),
            'adding' => __('Adding…', 'lets-payplus'),
            'added' => __('Added to your order — thank you!', 'lets-payplus'),
            'no_thanks' => __('No thanks', 'lets-payplus'),
            'error' => __('We could not add that. Please try again.', 'lets-payplus'),
            // Specific reason when the card wasn't saved at checkout (create_token off).
            'no_card' => __('Your saved card isn’t available for one-click add-ons, so we couldn’t add this. Your order is unchanged.', 'lets-payplus'),
        ),
    ));

    echo '<div class="lets-pp-upsell" data-lets-upsell hidden></div>';
}, 5);

/**
 * Register the thank-you assets. The card is drawn by the SHARED renderer + stylesheet
 * (assets/js|css/lets-ppu.*) — build-copied verbatim from the SaaS public/upsell/ so the
 * storefront card is byte-identical to the admin preview. The thank-you transport script depends
 * on the shared renderer; the shared stylesheet depends on the Heebo webfont (the house type).
 */
add_action('wp_enqueue_scripts', function () {
    // Heebo (house type). null version so Google's own cache headers apply.
    wp_register_style('lets-payplus-heebo', 'https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;700;900&display=swap', array(), null);
    // The ONE shared card stylesheet.
    wp_register_style('lets-payplus-ppu', LETS_PAYPLUS_URL . 'assets/css/lets-ppu.css', array('lets-payplus-heebo'), LETS_PAYPLUS_VERSION);
    // The ONE shared renderer (window.LetsUpsell).
    wp_register_script('lets-payplus-upsell-card', LETS_PAYPLUS_URL . 'assets/js/lets-ppu.js', array(), LETS_PAYPLUS_VERSION, true);
    // The thank-you transport depends on the shared renderer.
    wp_register_script('lets-payplus-thankyou', LETS_PAYPLUS_URL . 'assets/js/thankyou-upsell.js', array('lets-payplus-upsell-card'), LETS_PAYPLUS_VERSION, true);
});
