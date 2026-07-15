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
    wp_enqueue_script('lets-payplus-thankyou');
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
        ),
    ));

    echo '<div class="lets-pp-upsell" data-lets-upsell hidden></div>';
}, 5);

/** Register the thank-you asset (the shared style is registered by the product widget). */
add_action('wp_enqueue_scripts', function () {
    wp_register_script('lets-payplus-thankyou', LETS_PAYPLUS_URL . 'assets/js/thankyou-upsell.js', array(), LETS_PAYPLUS_VERSION, true);
});
