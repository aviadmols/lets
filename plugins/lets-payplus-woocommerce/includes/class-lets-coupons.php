<?php
/**
 * LETS — what a WooCommerce coupon does to the SUBSCRIPTION it was used on.
 *
 * WooCommerce only ever sees the checkout: every later cycle is charged by LETS
 * on the saved PayPlus token, so a coupon typed at checkout discounts the first
 * payment and nothing else — unless the merchant says otherwise. This file is
 * where they say it: a "subscription scope" on the coupon itself.
 *
 *   first_cycle  (default)  the coupon discounts the checkout only; renewals bill
 *                           the plan's regular price (today's behavior).
 *   all_cycles              the discounted amount actually paid at checkout becomes
 *                           the amount of EVERY cycle, for as long as the
 *                           subscription lives.
 *
 * The scope travels with the ORDER, not the coupon: at checkout it is stamped as
 * meta on the order's coupon line (together with the coupon's type and amount),
 * and LETS reads it back off the order over the WC REST API when the paid order
 * activates the plan (CheckoutPricingCapture). NON-underscore keys ON PURPOSE —
 * WooCommerce omits protected `_`-prefixed item meta from REST order responses
 * (same rule as lets_plan_public_id). Reading the coupon later would be wrong:
 * a merchant who edits the coupon next month must not re-price plans already
 * sold under the old terms.
 *
 * Loads AFTER subscriptions (locale helper lets_payplus_is_he).
 *
 * @package LETS_PayPlus
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** Coupon (post) meta: the merchant's choice on the coupon edit screen. */
define('LETS_COUPON_SCOPE_META', '_lets_subscription_scope');

/** Order coupon-line meta carrying the scope the order was sold under (REST-visible). */
define('LETS_COUPON_LINE_SCOPE_META', 'lets_subscription_scope');

/** Order coupon-line meta: the coupon's WooCommerce discount type (percent / fixed_cart / fixed_product). */
define('LETS_COUPON_LINE_TYPE_META', 'lets_coupon_type');

/** Order coupon-line meta: the coupon's configured amount (percent points or money). */
define('LETS_COUPON_LINE_AMOUNT_META', 'lets_coupon_amount');

/** The two scopes. Mirrors CheckoutPricingCapture::COUPON_SCOPE_*. */
define('LETS_COUPON_SCOPE_FIRST_CYCLE', 'first_cycle');

define('LETS_COUPON_SCOPE_ALL_CYCLES', 'all_cycles');

/** The scope a coupon without a choice (or an older plugin) is read as. */
define('LETS_COUPON_SCOPE_DEFAULT', LETS_COUPON_SCOPE_FIRST_CYCLE);

/** The scopes the edit screen offers, in display order. Labels resolve to the admin's locale. */
function lets_payplus_coupon_scopes()
{
    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();

    return array(
        LETS_COUPON_SCOPE_FIRST_CYCLE => $he ? 'המחזור הראשון בלבד (ברירת מחדל)' : 'First cycle only (default)',
        LETS_COUPON_SCOPE_ALL_CYCLES  => $he ? 'כל מחזורי המנוי' : 'Every subscription cycle',
    );
}

/** A scope string that is actually one of ours, else the default. */
function lets_payplus_coupon_scope_sanitize($scope)
{
    $scope = is_string($scope) ? trim($scope) : '';

    return array_key_exists($scope, lets_payplus_coupon_scopes()) ? $scope : LETS_COUPON_SCOPE_DEFAULT;
}

/** The scope saved on a coupon. Accepts a WC_Coupon or a coupon code. */
function lets_payplus_coupon_scope($coupon)
{
    if (! $coupon instanceof WC_Coupon) {
        $coupon = new WC_Coupon($coupon);
    }

    return lets_payplus_coupon_scope_sanitize((string) $coupon->get_meta(LETS_COUPON_SCOPE_META));
}

/* -------------------------------------------------------------------------
 * 1. The field on Marketing → Coupons → edit, General tab
 * ---------------------------------------------------------------------- */

add_action('woocommerce_coupon_options', 'lets_payplus_coupon_scope_field', 20, 2);

function lets_payplus_coupon_scope_field($coupon_id, $coupon)
{
    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();
    $current = $coupon instanceof WC_Coupon
        ? lets_payplus_coupon_scope($coupon)
        : lets_payplus_coupon_scope_sanitize(get_post_meta($coupon_id, LETS_COUPON_SCOPE_META, true));

    woocommerce_wp_select(array(
        'id'          => LETS_COUPON_SCOPE_META,
        'label'       => $he ? 'תחולה על מנויים (LETS)' : 'Subscription scope (LETS)',
        'options'     => lets_payplus_coupon_scopes(),
        'value'       => $current,
        'desc_tip'    => true,
        'description' => $he
            ? 'מה הקופון עושה למנוי שנרכש איתו: הנחה על התשלום הראשון בלבד, או שהמחיר ששולם בפועל נשמר לכל מחזור. חל רק על מוצרים עם תוכנית מנוי של LETS.'
            : 'What the coupon does to a subscription bought with it: discount the first payment only, or keep the price actually paid for every cycle. Applies only to products with a LETS subscription plan.',
    ));
}

add_action('woocommerce_coupon_options_save', 'lets_payplus_coupon_scope_save', 10, 2);

function lets_payplus_coupon_scope_save($coupon_id, $coupon)
{
    // Nonce + capability are WooCommerce's own on this screen (it calls this hook
    // from inside its verified save); the value is still reduced to our enum.
    $scope = isset($_POST[LETS_COUPON_SCOPE_META]) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ? lets_payplus_coupon_scope_sanitize(sanitize_text_field(wp_unslash($_POST[LETS_COUPON_SCOPE_META]))) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        : LETS_COUPON_SCOPE_DEFAULT;

    if ($coupon instanceof WC_Coupon) {
        $coupon->update_meta_data(LETS_COUPON_SCOPE_META, $scope);
        $coupon->save();

        return;
    }

    update_post_meta($coupon_id, LETS_COUPON_SCOPE_META, $scope);
}

/* -------------------------------------------------------------------------
 * 2. Stamp the scope on the order's coupon line at checkout
 * ---------------------------------------------------------------------- */

/** Classic checkout: WooCommerce hands us the coupon line as it creates it. */
add_action('woocommerce_checkout_create_order_coupon_item', 'lets_payplus_coupon_stamp_line', 10, 4);

function lets_payplus_coupon_stamp_line($item, $code, $coupon, $order)
{
    if (! $item instanceof WC_Order_Item_Coupon) {
        return;
    }

    $coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon((string) $code);

    $item->update_meta_data(LETS_COUPON_LINE_SCOPE_META, lets_payplus_coupon_scope($coupon));
    $item->update_meta_data(LETS_COUPON_LINE_TYPE_META, (string) $coupon->get_discount_type());
    $item->update_meta_data(LETS_COUPON_LINE_AMOUNT_META, (string) $coupon->get_amount());
}

/**
 * The Checkout BLOCK posts to the Store API, where the classic create-item hook
 * never fires. Stamp whatever coupon lines are still bare once the order exists.
 * Idempotent, so running it after a classic checkout too is harmless.
 */
add_action('woocommerce_store_api_checkout_order_processed', 'lets_payplus_coupon_stamp_order');
add_action('woocommerce_checkout_order_created', 'lets_payplus_coupon_stamp_order');

function lets_payplus_coupon_stamp_order($order)
{
    $order = ($order instanceof WC_Order) ? $order : wc_get_order($order);
    if (! $order instanceof WC_Order) {
        return;
    }

    $touched = false;
    foreach ($order->get_items('coupon') as $item) {
        if (! $item instanceof WC_Order_Item_Coupon || '' !== (string) $item->get_meta(LETS_COUPON_LINE_SCOPE_META)) {
            continue;
        }

        lets_payplus_coupon_stamp_line($item, $item->get_code(), null, $order);
        $item->save();
        $touched = true;
    }

    if ($touched) {
        $order->save();
    }
}

/* -------------------------------------------------------------------------
 * 3. Keep the raw keys off the admin order screen
 * ---------------------------------------------------------------------- */

add_filter('woocommerce_hidden_order_itemmeta', 'lets_payplus_coupon_hide_line_meta');

function lets_payplus_coupon_hide_line_meta($hidden)
{
    $hidden[] = LETS_COUPON_LINE_SCOPE_META;
    $hidden[] = LETS_COUPON_LINE_TYPE_META;
    $hidden[] = LETS_COUPON_LINE_AMOUNT_META;

    return $hidden;
}
