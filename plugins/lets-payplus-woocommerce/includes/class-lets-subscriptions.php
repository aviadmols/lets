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

/** Is the store locale Hebrew? (The plugin ships no translation catalog, so we branch inline.) */
function lets_payplus_is_he()
{
    return 0 === strpos((string) get_locale(), 'he');
}

/**
 * A human "כל חודש" / "every month" cadence label from a frequency + interval. Locale-aware inline
 * (Hebrew on a Hebrew store, English otherwise) because the plugin has no translation catalog — this
 * also makes the storefront subscribe widget's cadence Hebrew.
 */
function lets_payplus_cadence_label($frequency, $interval)
{
    $interval = max(1, (int) $interval);
    $he = lets_payplus_is_he();

    // [singular, plural] per frequency.
    $units = $he
        ? array(
            'daily'     => array('יום', 'ימים'),
            'weekly'    => array('שבוע', 'שבועות'),
            'biweekly'  => array('שבועיים', 'שבועיים'),
            'monthly'   => array('חודש', 'חודשים'),
            'quarterly' => array('רבעון', 'רבעונים'),
            'yearly'    => array('שנה', 'שנים'),
        )
        : array(
            'daily'     => array('day', 'days'),
            'weekly'    => array('week', 'weeks'),
            'biweekly'  => array('2 weeks', '2 weeks'),
            'monthly'   => array('month', 'months'),
            'quarterly' => array('quarter', 'quarters'),
            'yearly'    => array('year', 'years'),
        );
    $pair = isset($units[$frequency]) ? $units[$frequency] : array($frequency, $frequency);
    $unit = 1 === $interval ? $pair[0] : $pair[1];

    if (1 === $interval) {
        return $he ? ('כל ' . $unit) : ('every ' . $unit);
    }

    return $he ? ('כל ' . $interval . ' ' . $unit) : ('every ' . $interval . ' ' . $unit);
}

/**
 * The subscription line-item PROPERTY (key + value) shown in the cart AND on the order — e.g.
 * "מנוי: מתחדש כל חודש" / "Subscription: renews every month". Locale-aware.
 *
 * @return array{key:string,value:string}
 */
function lets_payplus_subscription_property($frequency, $interval)
{
    $he = lets_payplus_is_he();

    return array(
        'key'   => $he ? 'מנוי' : 'Subscription',
        'value' => ($he ? 'מתחדש ' : 'renews ') . lets_payplus_cadence_label($frequency, $interval),
    );
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

    // The shopper explicitly chose a one-time purchase — nothing to do (and no lookup needed).
    if ('one_time' === $mode) {
        return $cart_item_data;
    }

    $variant_id = (int) $variation_id ?: (int) $product_id;
    $config = lets_payplus_sub_config((int) $product_id, $variant_id);
    if (! is_array($config) || empty($config['has_subscription']) || empty($config['subscription'])) {
        return $cart_item_data; // not a subscription product — unchanged
    }

    // `lets_purchase_mode` only exists INSIDE the product-page add-to-cart form. Adding from the
    // shop/category listing (the AJAX add-to-cart button), a block, or related-products submits no
    // form, so the mode arrives EMPTY. For a subscription-ONLY product there is no other way to buy
    // it, so an empty mode is still a subscribe. Without this the product was silently bought
    // ONE-TIME at full price — no plan, no token, no cart/order property.
    if ('' === $mode && empty($config['one_time_allowed'])) {
        $mode = 'subscribe';
    }

    if ('subscribe' !== $mode) {
        return $cart_item_data; // a one-time-capable product added without choosing → unchanged
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

// Show the subscription plan under the cart/checkout line — e.g. "מנוי: מתחדש כל חודש".
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
    if (! empty($cart_item['_lets_subscription'])) {
        $sub = $cart_item['_lets_subscription'];
        $prop = lets_payplus_subscription_property($sub['billing_frequency'], $sub['interval_count']);
        $item_data[] = array('key' => $prop['key'], 'value' => $prop['value']);
    }

    return $item_data;
}, 10, 2);

// Persist onto the ORDER line: the HIDDEN intent the gateway reads, PLUS a VISIBLE property so the plan
// shows under the line item on the order-received page, the admin order screen, and the order emails.
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    if (empty($values['_lets_subscription'])) {
        return;
    }
    $sub = $values['_lets_subscription'];
    $item->add_meta_data('_lets_subscription', $sub, true); // hidden (underscore) — read by the gateway
    $prop = lets_payplus_subscription_property($sub['billing_frequency'], $sub['interval_count']);
    $item->add_meta_data($prop['key'], $prop['value'], true); // visible — shown on the order everywhere
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
    lets_payplus_metabox_styles();
    $he = lets_payplus_is_he();

    if (lets_payplus_connection() === null) {
        echo lets_payplus_mb_card('neutral', '🔌',
            $he ? 'לא מחובר' : 'Not connected',
            $he ? 'חבר את החנות ל-LETS כדי למכור מנויים.' : 'Connect this store to LETS to sell subscriptions.'
        );

        return;
    }

    $product_id = (int) $post->ID;
    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
    $type = is_object($product) && method_exists($product, 'get_type') ? $product->get_type() : 'simple';

    // External/Affiliate + Grouped have no price/stock/add-to-cart button → can't be sold or subscribed.
    if (in_array($type, array('external', 'grouped'), true)) {
        echo lets_payplus_mb_card('warn', '⚠️',
            $he ? 'סוג מוצר לא נתמך' : "Can’t be sold as a subscription",
            $he
                ? 'מוצר מסוג חיצוני/שותפים או מקובץ אין לו מחיר, מלאי או כפתור הוספה לסל. החלף ל״מוצר פשוט״ (בתיבת נתוני המוצר) ואז הגדר תוכנית ב-LETS.'
                : 'External/Affiliate & Grouped products have no price, stock or add-to-cart button. Switch it to a “Simple product” (in the Product data box), then define a plan in LETS.',
            lets_payplus_mb_app_link($he ? 'פתח מוצרים ב-LETS →' : 'Open LETS products →')
        );

        return;
    }

    $config = lets_payplus_sub_config($product_id, $product_id);

    // 1) An ACTIVE plan — recognized.
    if (is_array($config) && ! empty($config['has_subscription']) && ! empty($config['subscription'])) {
        $sub = $config['subscription'];
        $cadence = lets_payplus_cadence_label($sub['billing_frequency'], $sub['interval_count']);
        if (('percent' === ($sub['discount_type'] ?? '')) && (float) $sub['discount_value'] > 0) {
            $pct = rtrim(rtrim((string) (float) $sub['discount_value'], '0'), '.');
            $cadence .= ' · ' . ($he ? ($pct . '% הנחה') : ($pct . '% off'));
        }
        $price = function_exists('wc_price') ? wc_price((float) $sub['price_per_cycle']) : esc_html((string) $sub['price_per_cycle']);

        $body = '';
        if (! empty($sub['plan_name'])) {
            $body .= '<div class="lets-mb__name">' . esc_html($sub['plan_name']) . '</div>';
        }
        $body .= '<div class="lets-mb__meta">' . esc_html($cadence) . '</div>';
        $body .= '<div class="lets-mb__price">' . wp_kses_post($price)
            . ' <span class="lets-mb__muted">' . ($he ? 'לחיוב' : 'per cycle') . '</span></div>';
        $body .= '<p class="lets-mb__hint">' . ($he
            ? 'הלקוחות רואים אפשרות ״הרשמה למנוי״ במוצר.'
            : 'Shoppers see a “Subscribe” option on this product.') . '</p>';

        echo lets_payplus_mb_card('ok', '✓',
            $he ? 'מנוי פעיל' : 'Active subscription',
            $body,
            lets_payplus_mb_app_link($he ? 'ערוך ב-LETS →' : 'Edit in LETS →'),
            true // body is pre-built HTML
        );

        return;
    }

    // 2) A plan exists but is still DRAFT — the merchant just needs to activate it.
    if (is_array($config) && ! empty($config['draft_subscription'])) {
        echo lets_payplus_mb_card('draft', '⏳',
            $he ? 'כמעט מוכן' : 'Almost ready',
            $he
                ? 'הגדרת תוכנית מנוי, אבל היא עדיין בטיוטה. הפעל אותה ב-LETS כדי שהמוצר יימכר כמנוי.'
                : 'A subscription plan is defined, but it’s still a Draft. Activate it in LETS to start selling this as a subscription.',
            lets_payplus_mb_app_link($he ? 'הפעל את התוכנית ב-LETS →' : 'Activate the plan in LETS →')
        );

        return;
    }

    // 3) No plan at all.
    echo lets_payplus_mb_card('neutral', '↻',
        $he ? 'לא מוגדר כמנוי' : 'Not a subscription yet',
        $he
            ? 'כדי למכור אותו כמנוי: ודא שהמוצר מסונכרן ל-LETS, ואז הגדר והפעל עבורו תוכנית מנוי באפליקציה.'
            : 'To sell it as a subscription: make sure it’s synced to LETS, then define and activate a plan for it in the app.',
        lets_payplus_mb_app_link($he ? 'פתח מוצרים ב-LETS →' : 'Open LETS products →')
    );
}

/**
 * A compact, styled meta-box card. $body is escaped as plain text unless $body_is_html (the caller
 * pre-built + escaped its own inner markup, e.g. the price line). $footer is trusted HTML (the app link).
 */
function lets_payplus_mb_card($variant, $icon, $title, $body, $footer = '', $body_is_html = false)
{
    $variant = in_array($variant, array('ok', 'draft', 'warn', 'neutral'), true) ? $variant : 'neutral';
    $body_html = $body_is_html ? $body : ('<p class="lets-mb__text">' . esc_html($body) . '</p>');

    return '<div class="lets-mb lets-mb--' . esc_attr($variant) . '">'
        . '<div class="lets-mb__head">'
        . '<span class="lets-mb__badge" aria-hidden="true">' . esc_html($icon) . '</span>'
        . '<span class="lets-mb__title">' . esc_html($title) . '</span>'
        . '</div>'
        . '<div class="lets-mb__body">' . $body_html . '</div>'
        . ($footer !== '' ? ('<div class="lets-mb__foot">' . $footer . '</div>') : '')
        . '</div>';
}

/** A link to the LETS app products screen (origin derived from the stored connection). Returns ''. */
function lets_payplus_mb_app_link($label)
{
    $conn = lets_payplus_connection();
    $origin = is_array($conn) ? lets_payplus_saas_origin($conn) : '';
    if ($origin === '') {
        return '';
    }

    return '<a class="lets-mb__link" href="' . esc_url($origin . '/admin/products') . '" target="_blank" rel="noopener">'
        . esc_html($label) . '</a>';
}

/** Scoped styles for the product-edit meta box card — printed once per request. */
function lets_payplus_metabox_styles()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
.lets-mb{border:1px solid #e2e4e7;border-inline-start-width:3px;border-radius:8px;padding:12px 12px 10px;background:#fff;line-height:1.5}
.lets-mb__head{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.lets-mb__badge{flex:0 0 auto;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;line-height:1;color:#fff;background:#8c8f94}
.lets-mb__title{font-weight:600;font-size:13px}
.lets-mb__body{font-size:12.5px;color:#3c434a}
.lets-mb__text{margin:0}
.lets-mb__name{font-weight:600;font-size:13px;color:#1d2327;margin-bottom:2px}
.lets-mb__meta{color:#50575e}
.lets-mb__price{margin-top:2px;font-weight:600;color:#1d2327}
.lets-mb__muted,.lets-mb__hint{color:#787c82;font-weight:400}
.lets-mb__hint{margin:6px 0 0;font-size:12px}
.lets-mb__foot{margin-top:10px}
.lets-mb__link{display:inline-block;font-size:12.5px;font-weight:600;text-decoration:none}
.lets-mb--ok{border-inline-start-color:#1a7f37;background:#f4faf6}
.lets-mb--ok .lets-mb__badge{background:#1a7f37}
.lets-mb--draft{border-inline-start-color:#bf8700;background:#fdfaf0}
.lets-mb--draft .lets-mb__badge{background:#bf8700}
.lets-mb--warn{border-inline-start-color:#d63638;background:#fdf5f5}
.lets-mb--warn .lets-mb__badge{background:#d63638}
.lets-mb--neutral{border-inline-start-color:#8c8f94}
</style>
<?php
}

/** Register the (variable-product) subscription widget script. */
add_action('wp_enqueue_scripts', function () {
    wp_register_script('lets-payplus-subscription-widget', LETS_PAYPLUS_URL . 'assets/js/subscription-widget.js', array('jquery'), LETS_PAYPLUS_VERSION, true);
});
