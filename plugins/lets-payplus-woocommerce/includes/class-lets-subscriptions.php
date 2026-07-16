<?php
/**
 * LETS — cart-based SUBSCRIPTION products (W17 Part B).
 *
 * A WooCommerce product that has an active LETS subscription plan is MARKED (admin list column +
 * a product-page choice), and adding it to the cart as "subscribe" carries the intent through the
 * NORMAL WooCommerce checkout. When the shopper pays on the LETS PayPlus gateway, the SaaS starts a
 * recurring plan per the merchant's template (cadence/discount server-resolved) and the recurring
 * engine bills every cycle thereafter — no WooCommerce Subscriptions plugin required.
 *
 * Data flow:
 *   product page  → GET the resolved plan config (/subscriptions/config) → render one-time|subscribe
 *   add to cart   → woocommerce_add_cart_item_data stamps `_lets_subscription` (mode + price + cadence)
 *   cart/checkout → line price = server per-cycle price; the line meta persists to the order
 *   gateway       → class-lets-gateway collects the subscription line items and sends them to the SaaS
 *
 * Money is display-only here: the SaaS ALWAYS recomputes the charged per-cycle amount from its own
 * catalog + template at gateway/session time (this file never sends a price the SaaS trusts).
 */

if (! defined('ABSPATH')) {
    exit;
}

define('LETS_PAYPLUS_SUB_CACHE_TTL', 300); // seconds to cache a product's resolved plan config

/**
 * Resolve a product/variant's subscription config from the SaaS (transient-cached). Returns the
 * decoded array {has_subscription, one_time_allowed, subscription:{...}} or null on failure.
 */
function lets_payplus_sub_config($product_id, $variant_id)
{
    $product_id = (int) $product_id;
    $variant_id = (int) $variant_id ?: $product_id;
    $cache_key = 'lets_pp_cfg_' . $product_id . '_' . $variant_id;

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $res = lets_payplus_signed_post('/api/woocommerce/subscriptions/config', array(
        'product_id' => (string) $product_id,
        'variant_id' => (string) $variant_id,
    ));
    if (is_wp_error($res) || ! is_array($res)) {
        return null;
    }

    set_transient($cache_key, $res, LETS_PAYPLUS_SUB_CACHE_TTL);

    return $res;
}

/** Browser → plugin proxy for /subscriptions/config (nonce-guarded; the plugin signs the SaaS call). */
add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/subscriptions/config', array(
        'methods' => 'POST',
        'callback' => 'lets_payplus_rest_sub_config',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

function lets_payplus_rest_sub_config(WP_REST_Request $request)
{
    $config = lets_payplus_sub_config((int) $request->get_param('product_id'), (int) $request->get_param('variant_id'));

    return new WP_REST_Response(is_array($config) ? $config : array('has_subscription' => false), 200);
}

/** A human "every month" / "every 2 weeks" label from a frequency + interval. */
function lets_payplus_cadence_label($frequency, $interval)
{
    $interval = max(1, (int) $interval);
    $units = array(
        'daily'     => array(__('day', 'lets-payplus'), __('days', 'lets-payplus')),
        'weekly'    => array(__('week', 'lets-payplus'), __('weeks', 'lets-payplus')),
        'biweekly'  => array(__('2 weeks', 'lets-payplus'), __('2 weeks', 'lets-payplus')),
        'monthly'   => array(__('month', 'lets-payplus'), __('months', 'lets-payplus')),
        'quarterly' => array(__('quarter', 'lets-payplus'), __('quarters', 'lets-payplus')),
        'yearly'    => array(__('year', 'lets-payplus'), __('years', 'lets-payplus')),
    );
    $pair = isset($units[$frequency]) ? $units[$frequency] : array($frequency, $frequency);
    $unit = 1 === $interval ? $pair[0] : $pair[1];

    return 1 === $interval
        /* translators: %s: billing unit (month/week/...). */
        ? sprintf(__('every %s', 'lets-payplus'), $unit)
        /* translators: 1: interval count, 2: billing unit. */
        : sprintf(__('every %1$d %2$s', 'lets-payplus'), $interval, $unit);
}

// ============================================================================
// Product page — the one-time / subscribe choice (inside the add-to-cart form)
// ============================================================================

add_action('woocommerce_before_add_to_cart_button', function () {
    if (lets_payplus_connection() === null) {
        return;
    }
    global $product;
    if (! is_object($product) || ! method_exists($product, 'get_id')) {
        return;
    }

    $product_id = (int) $product->get_id();
    $variant_id = $product->is_type('variable') ? 0 : $product_id;
    $config = lets_payplus_sub_config($product_id, $variant_id ?: $product_id);
    if (! is_array($config) || empty($config['has_subscription']) || empty($config['subscription'])) {
        return; // not a subscription product — leave the normal add-to-cart alone
    }

    $sub = $config['subscription'];
    $one_time = ! empty($config['one_time_allowed']);
    $currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
    $cadence = lets_payplus_cadence_label($sub['billing_frequency'], $sub['interval_count']);
    $sub_price = wc_price((float) $sub['price_per_cycle']);
    $base_price = wc_price((float) ($sub['base_price'] ?? 0));

    echo '<div class="lets-pp-sub" data-lets-sub'
        . ' data-product="' . esc_attr($product_id) . '"'
        . ' data-cadence="' . esc_attr($cadence) . '">';

    if ($one_time) {
        echo '<p class="lets-pp-sub__label"><strong>' . esc_html__('Choose how to buy', 'lets-payplus') . '</strong></p>';
        echo '<label class="lets-pp-sub__opt"><input type="radio" name="lets_purchase_mode" value="one_time"> '
            . '<span>' . esc_html__('One-time', 'lets-payplus') . ' — ' . wp_kses_post($base_price) . '</span></label>';
        echo '<label class="lets-pp-sub__opt"><input type="radio" name="lets_purchase_mode" value="subscribe" checked> '
            . '<span data-lets-sub-price>' . esc_html__('Subscribe', 'lets-payplus') . ' — ' . wp_kses_post($sub_price)
            . ' <span class="lets-pp-sub__cadence">' . esc_html($cadence) . '</span></span></label>';
    } else {
        // Subscription-only product: force the mode + explain.
        echo '<input type="hidden" name="lets_purchase_mode" value="subscribe">';
        echo '<p class="lets-pp-sub__note" data-lets-sub-price>'
            . esc_html__('Sold as a subscription', 'lets-payplus') . ' — ' . wp_kses_post($sub_price)
            . ' <span class="lets-pp-sub__cadence">' . esc_html($cadence) . '</span></p>';
    }

    echo '</div>';

    // Variable products: re-price the choice when a variation is selected.
    if ($product->is_type('variable')) {
        wp_enqueue_script('lets-payplus-subscription-widget');
        wp_localize_script('lets-payplus-subscription-widget', 'LetsPayPlusSub', array(
            'restConfig' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/subscriptions/config')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'productId'  => $product_id,
            'symbol'     => $currency,
            'i18n'       => array(
                'subscribe' => __('Subscribe', 'lets-payplus'),
                'sold_as'   => __('Sold as a subscription', 'lets-payplus'),
            ),
        ));
    }
}, 25);

// ============================================================================
// Cart — carry the subscription intent + the server per-cycle price
// ============================================================================

add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {
    $mode = isset($_POST['lets_purchase_mode']) ? sanitize_text_field(wp_unslash($_POST['lets_purchase_mode'])) : '';
    if ('subscribe' !== $mode) {
        return $cart_item_data; // one-time or a normal product — unchanged
    }

    $variant_id = (int) $variation_id ?: (int) $product_id;
    $config = lets_payplus_sub_config((int) $product_id, $variant_id);
    if (! is_array($config) || empty($config['has_subscription']) || empty($config['subscription'])) {
        return $cart_item_data; // not actually a subscription — ignore the mode
    }

    $sub = $config['subscription'];
    $cart_item_data['_lets_subscription'] = array(
        'product_id'        => (string) $product_id,
        'variant_id'        => (string) $variant_id,
        'mode'              => 'subscribe',
        'price_per_cycle'   => (float) $sub['price_per_cycle'],
        'billing_frequency' => (string) $sub['billing_frequency'],
        'interval_count'    => (int) $sub['interval_count'],
    );
    // Keep a subscribe line distinct from a one-time line of the same product, but DETERMINISTIC so
    // two subscribe-adds of the same variant MERGE into one qty-N line (→ one qty-aware plan), not
    // two separate plans.
    $cart_item_data['lets_unique'] = 'sub_' . $variant_id;

    return $cart_item_data;
}, 10, 3);

// The line the shopper pays now = the per-cycle price (the first cycle).
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && ! defined('DOING_AJAX')) {
        return;
    }
    if (! is_object($cart) || ! method_exists($cart, 'get_cart')) {
        return;
    }
    foreach ($cart->get_cart() as $item) {
        if (! empty($item['_lets_subscription']['price_per_cycle']) && isset($item['data']) && is_object($item['data'])) {
            $item['data']->set_price((float) $item['_lets_subscription']['price_per_cycle']);
        }
    }
}, 20);

// Show "Subscription — every month" under the cart/checkout line.
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
    if (! empty($cart_item['_lets_subscription'])) {
        $sub = $cart_item['_lets_subscription'];
        $item_data[] = array(
            'key'   => __('Purchase', 'lets-payplus'),
            'value' => __('Subscription', 'lets-payplus') . ' — ' . lets_payplus_cadence_label($sub['billing_frequency'], $sub['interval_count']),
        );
    }

    return $item_data;
}, 10, 2);

// Persist the intent onto the ORDER line so the gateway can read it server-side.
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    if (! empty($values['_lets_subscription'])) {
        $item->add_meta_data('_lets_subscription', $values['_lets_subscription'], true);
    }
}, 10, 4);

// Only the LETS gateway vaults a token, so a subscription cart MUST checkout through it. If the LETS
// gateway is unavailable, BLOCK checkout (empty list) rather than fall open to another gateway —
// paying a subscription on a non-LETS gateway would take the first cycle but start no plan.
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (is_admin() || ! function_exists('WC') || ! WC()->cart) {
        return $gateways;
    }
    foreach (WC()->cart->get_cart() as $item) {
        if (! empty($item['_lets_subscription'])) {
            return isset($gateways['lets_payplus']) ? array('lets_payplus' => $gateways['lets_payplus']) : array();
        }
    }

    return $gateways;
});

// ============================================================================
// Admin — mark subscription products in the WooCommerce products list
// ============================================================================

add_filter('manage_edit-product_columns', function ($columns) {
    $columns['lets_subscription'] = __('LETS Subscription', 'lets-payplus');

    return $columns;
}, 20);

add_action('manage_product_posts_custom_column', function ($column, $post_id) {
    if ('lets_subscription' !== $column) {
        return;
    }
    $flags = lets_payplus_sub_flags_for_screen();
    echo ! empty($flags[(string) $post_id]) ? '<span title="Subscription">🔁</span>' : '—';
}, 10, 2);

/**
 * One bulk /subscriptions/flags call for every product on the current admin list page, cached in a
 * request-static so the per-row column render is free. Reads the ids from the running WP_Query.
 */
function lets_payplus_sub_flags_for_screen()
{
    static $flags = null;
    if (null !== $flags) {
        return $flags;
    }
    $flags = array();

    if (lets_payplus_connection() === null || empty($GLOBALS['wp_query']->posts)) {
        return $flags;
    }

    $ids = array();
    foreach ($GLOBALS['wp_query']->posts as $p) {
        $ids[] = (string) (is_object($p) ? $p->ID : $p);
    }
    if (! $ids) {
        return $flags;
    }

    // Transient-cache the flags for this exact page of ids so we don't call the SaaS on every list
    // render (the docblock promises "cheap"). Keyed by the sorted id set.
    sort($ids);
    $cache_key = 'lets_pp_flags_' . md5(implode(',', $ids));
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $flags = $cached;

        return $flags;
    }

    $res = lets_payplus_signed_post('/api/woocommerce/subscriptions/flags', array('product_ids' => $ids));
    if (! is_wp_error($res) && ! empty($res['flags']) && is_array($res['flags'])) {
        $flags = $res['flags'];
        set_transient($cache_key, $flags, LETS_PAYPLUS_SUB_CACHE_TTL);
    }

    return $flags;
}

// ============================================================================
// Admin — product-EDIT "LETS Subscription" panel (W19)
// ============================================================================
// A meta box on the product edit screen that ASKS THE LETS APP whether this product has a matching
// subscription plan, and shows it — so the merchant knows it's recognized without hunting for a
// WooCommerce product type. A meta box (not a woocommerce_product_data_* panel) renders for EVERY
// product type, so it can also warn on the External/Affiliate type that silently disables selling.

add_action('add_meta_boxes', function () {
    add_meta_box(
        'lets_payplus_subscription',
        __('LETS Subscription', 'lets-payplus'),
        'lets_payplus_render_product_metabox',
        'product',
        'side',
        'default'
    );
});

function lets_payplus_render_product_metabox($post)
{
    if (lets_payplus_connection() === null) {
        echo '<p>' . esc_html__('Connect this store to LETS to sell subscriptions.', 'lets-payplus') . '</p>';

        return;
    }

    $product_id = (int) $post->ID;
    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
    $type = is_object($product) && method_exists($product, 'get_type') ? $product->get_type() : 'simple';

    // External/Affiliate + Grouped have no price/stock/add-to-cart button → can't be sold or subscribed.
    if (in_array($type, array('external', 'grouped'), true)) {
        echo '<div class="notice notice-warning inline"><p><strong>'
            . esc_html__('This product type can’t be sold or subscribed.', 'lets-payplus') . '</strong><br>'
            . esc_html__('Switch it to “Simple product” (in the Product data box) so it has a price, stock and an add-to-cart button — then define a plan in the LETS app.', 'lets-payplus')
            . '</p></div>';
        lets_payplus_metabox_app_link();

        return;
    }

    $config = lets_payplus_sub_config($product_id, $product_id);

    if (is_array($config) && ! empty($config['has_subscription']) && ! empty($config['subscription'])) {
        $sub = $config['subscription'];
        $line = lets_payplus_cadence_label($sub['billing_frequency'], $sub['interval_count']);
        if (('percent' === ($sub['discount_type'] ?? '')) && (float) $sub['discount_value'] > 0) {
            /* translators: %s: percent discount. */
            $line .= ' · ' . sprintf(__('%s%% off', 'lets-payplus'), rtrim(rtrim((string) (float) $sub['discount_value'], '0'), '.'));
        }

        echo '<p><strong style="color:#1a7f37">✅ ' . esc_html__('Recognized as a LETS subscription', 'lets-payplus') . '</strong></p>';
        if (! empty($sub['plan_name'])) {
            echo '<p><strong>' . esc_html($sub['plan_name']) . '</strong></p>';
        }
        echo '<p>' . esc_html($line) . '<br>';
        echo function_exists('wc_price') ? wp_kses_post(wc_price((float) $sub['price_per_cycle'])) : esc_html((string) $sub['price_per_cycle']);
        echo ' ' . esc_html__('per cycle', 'lets-payplus') . '</p>';
        echo '<p class="description">' . esc_html__('Shoppers see a “Subscribe” option on this product. Edit the plan in the LETS app.', 'lets-payplus') . '</p>';
    } else {
        echo '<div class="notice notice-info inline"><p>'
            . esc_html__('No LETS subscription plan detected for this product.', 'lets-payplus') . '</p></div>';
        echo '<p>' . esc_html__('To sell it as a subscription:', 'lets-payplus') . '</p>';
        echo '<ol style="margin:0;padding-inline-start:18px">';
        echo '<li>' . esc_html__('Sync this product to LETS (Refresh products in the LETS dashboard).', 'lets-payplus') . '</li>';
        echo '<li>' . esc_html__('Define AND activate a subscription plan for it in the LETS app.', 'lets-payplus') . '</li>';
        echo '</ol>';
    }

    lets_payplus_metabox_app_link();
}

/** A link to the LETS app products screen (origin derived from the stored connection). */
function lets_payplus_metabox_app_link()
{
    $conn = lets_payplus_connection();
    $origin = is_array($conn) ? lets_payplus_saas_origin($conn) : '';
    if ($origin !== '') {
        echo '<p style="margin-top:10px"><a href="' . esc_url($origin . '/admin/products') . '" target="_blank" rel="noopener">'
            . esc_html__('Open LETS products →', 'lets-payplus') . '</a></p>';
    }
}

/** Register the (variable-product) subscription widget script. */
add_action('wp_enqueue_scripts', function () {
    wp_register_script('lets-payplus-subscription-widget', LETS_PAYPLUS_URL . 'assets/js/subscription-widget.js', array('jquery'), LETS_PAYPLUS_VERSION, true);
});
