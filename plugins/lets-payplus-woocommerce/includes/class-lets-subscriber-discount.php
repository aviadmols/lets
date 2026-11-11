<?php
/**
 * LETS — subscribers get N% off chosen store categories.
 *
 * The RULE (percent + categories) is set in LETS → Settings → Billing and rides
 * the cached account shell, so a cart in a shop that never turned it on costs no
 * round trip. The QUESTION "is this email a subscriber right now?" is LETS's to
 * answer (POST account/subscriber-status: a recurring plan, paid and running —
 * never paused, failing or an installment plan), asked only when the cart holds
 * a product the rule covers, and cached per email for a few minutes.
 *
 * The email is the one the shopper TYPES at checkout — guests included, as the
 * merchant asked. Classic checkout re-totals when the email field changes (the
 * inline script below); the block checkout re-totals by itself.
 *
 * The discount is a lower PRICE on each eligible line, not a cart fee: the order,
 * the PayPlus amount and the invoice then all carry the real price per product.
 * Subscription lines are never discounted.
 *
 * FAIL-CLOSED: if LETS cannot be asked, the shopper pays the regular price. This
 * rule hands out money; a timeout must not.
 *
 * @package LETS_PayPlus
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** How long an answer for one email is trusted. */
define('LETS_SD_STATUS_TTL', 300);

/** How long a FAILED lookup is remembered (no discount meanwhile), so a down LETS is not hammered. */
define('LETS_SD_STATUS_TTL_FAILED', 30);

/** Ceiling mirrored from MerchantBillingSettings::MAX_SUBSCRIBER_DISCOUNT_PERCENT. */
define('LETS_SD_MAX_PERCENT', 90);

/** Cart-item key marking a line the discount was applied to (the percent). */
define('LETS_SD_ITEM_KEY', 'lets_subscriber_discount');

/**
 * The shop's rule from the cached shell, validated, or null when off.
 *
 * @return array{percent: float, category_ids: int[]}|null
 */
function lets_payplus_subscriber_discount_rule()
{
    if (! function_exists('lets_payplus_account_shell_config') || null === lets_payplus_connection()) {
        return null;
    }

    $config = lets_payplus_account_shell_config();
    $rule   = isset($config['purchase']['subscriber_discount']) ? $config['purchase']['subscriber_discount'] : null;
    if (! is_array($rule)) {
        return null;
    }

    $percent = isset($rule['percent']) ? (float) $rule['percent'] : 0.0;
    $ids     = array_values(array_filter(array_map('absint', isset($rule['category_ids']) ? (array) $rule['category_ids'] : array())));

    if ($percent <= 0 || $percent > LETS_SD_MAX_PERCENT || array() === $ids) {
        return null;
    }

    return array('percent' => $percent, 'category_ids' => $ids);
}

/**
 * The email to judge: what the shopper typed at checkout, else what WooCommerce
 * already knows about them.
 *
 * @return string lower-cased email, or ''
 */
function lets_payplus_subscriber_discount_email()
{
    $email = '';

    // Classic checkout: the order submit carries the field, the re-total carries it inside post_data.
    if (defined('WOOCOMMERCE_CHECKOUT') && WOOCOMMERCE_CHECKOUT) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its own request.
        if (! empty($_POST['billing_email'])) {
            $email = sanitize_email(wp_unslash($_POST['billing_email']));
        } elseif (! empty($_POST['post_data'])) {
            $posted = array();
            parse_str(wp_unslash((string) $_POST['post_data']), $posted);
            $email = isset($posted['billing_email']) ? sanitize_email((string) $posted['billing_email']) : '';
        }
        // phpcs:enable
    }

    // Block checkout (the Store API stores it on the customer), or a returning shopper.
    if ('' === $email && function_exists('WC') && WC()->customer) {
        $email = sanitize_email((string) WC()->customer->get_billing_email());
    }

    if ('' === $email && is_user_logged_in()) {
        $email = sanitize_email((string) wp_get_current_user()->user_email);
    }

    $email = strtolower(trim($email));

    return is_email($email) ? $email : '';
}

/**
 * Is this email a subscriber right now? One LETS round trip per email per TTL.
 *
 * @param  string  $email
 * @return bool
 */
function lets_payplus_subscriber_discount_is_subscriber($email)
{
    static $memo = array();

    if ('' === $email) {
        return false;
    }

    $ref = is_user_logged_in() ? (string) get_current_user_id() : $email;
    $key = 'lets_sd_' . md5($ref . '|' . $email);

    if (isset($memo[$key])) {
        return $memo[$key];
    }

    $cached = get_transient($key);
    if (false !== $cached) {
        return $memo[$key] = ('1' === $cached);
    }

    $result = lets_payplus_signed_post('/api/woocommerce/account/subscriber-status', array(
        'customer_ref' => $ref,
        'email'        => $email,
    ));

    if (is_wp_error($result)) {
        set_transient($key, '0', LETS_SD_STATUS_TTL_FAILED);

        return $memo[$key] = false;
    }

    $active = ! empty($result['active']);
    set_transient($key, $active ? '1' : '0', LETS_SD_STATUS_TTL);

    return $memo[$key] = $active;
}

/**
 * Does this cart line fall under the rule? Its product (or, for a variation,
 * the parent) sits in a chosen category or under one.
 *
 * @param  array  $item
 * @param  int[]  $category_ids
 * @return bool
 */
function lets_payplus_subscriber_discount_covers($item, $category_ids)
{
    if (! empty($item['_lets_subscription']) || empty($item['data']) || ! $item['data'] instanceof WC_Product) {
        return false;
    }

    $product = $item['data'];
    $id      = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
    $terms   = array_map('intval', (array) wc_get_product_term_ids($id, 'product_cat'));

    foreach ($terms as $term) {
        if (in_array($term, $category_ids, true)) {
            return true;
        }
        foreach ((array) get_ancestors($term, 'product_cat', 'taxonomy') as $ancestor) {
            if (in_array((int) $ancestor, $category_ids, true)) {
                return true;
            }
        }
    }

    return false;
}

/* -------------------------------------------------------------------------
 * The price itself
 * ---------------------------------------------------------------------- */

add_action('woocommerce_before_calculate_totals', function ($cart) {
    // The shelf price each line had before we touched it, per request — totals
    // are calculated several times in one request and must never compound.
    static $base = array();

    if ((is_admin() && ! wp_doing_ajax()) || ! $cart instanceof WC_Cart) {
        return;
    }

    $rule = lets_payplus_subscriber_discount_rule();

    $covered = array();
    foreach ($cart->get_cart() as $key => $item) {
        unset($cart->cart_contents[$key][LETS_SD_ITEM_KEY]); // re-earned every time, or not at all
        if (null !== $rule && lets_payplus_subscriber_discount_covers($item, $rule['category_ids'])) {
            $covered[] = $key;
        }
    }

    if (array() === $covered || ! lets_payplus_subscriber_discount_is_subscriber(lets_payplus_subscriber_discount_email())) {
        // A line discounted earlier in this request (the email changed) gets its price back.
        foreach ($base as $key => $price) {
            if (isset($cart->cart_contents[$key]['data'])) {
                $cart->cart_contents[$key]['data']->set_price($price);
            }
        }

        return;
    }

    $factor = 1 - ($rule['percent'] / 100);
    foreach ($covered as $key) {
        $product = $cart->cart_contents[$key]['data'];
        if (! isset($base[$key])) {
            $base[$key] = (float) $product->get_price();
        }
        $product->set_price(round($base[$key] * $factor, wc_get_price_decimals()));
        $cart->cart_contents[$key][LETS_SD_ITEM_KEY] = $rule['percent'];
    }
}, 20);

/* -------------------------------------------------------------------------
 * Saying so — in the cart, and on the order
 * ---------------------------------------------------------------------- */

/** The label shown beside a discounted line. */
function lets_payplus_subscriber_discount_label()
{
    return function_exists('lets_payplus_is_he') && lets_payplus_is_he() ? 'הנחת מנוי' : 'Subscriber discount';
}

/** Format "20%" / "12.5%". */
function lets_payplus_subscriber_discount_percent_text($percent)
{
    return rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.') . '%';
}

add_filter('woocommerce_get_item_data', function ($data, $cart_item) {
    if (! empty($cart_item[LETS_SD_ITEM_KEY])) {
        $data[] = array(
            'key'   => lets_payplus_subscriber_discount_label(),
            'value' => lets_payplus_subscriber_discount_percent_text($cart_item[LETS_SD_ITEM_KEY]),
        );
    }

    return $data;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
    if (! empty($values[LETS_SD_ITEM_KEY])) {
        $item->add_meta_data(lets_payplus_subscriber_discount_label(), lets_payplus_subscriber_discount_percent_text($values[LETS_SD_ITEM_KEY]), true);
    }
}, 10, 3);

/**
 * Classic checkout re-totals on address fields only. Make the email field one
 * of them, so typing a subscriber's email drops the price before they pay.
 */
add_action('wp_enqueue_scripts', function () {
    if (! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() || null === lets_payplus_subscriber_discount_rule()) {
        return;
    }

    wp_add_inline_script(
        'wc-checkout',
        'jQuery(function($){$(document.body).on("change","#billing_email",function(){$(document.body).trigger("update_checkout");});});'
    );
}, 20);
