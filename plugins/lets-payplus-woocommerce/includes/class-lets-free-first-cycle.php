<?php
/**
 * LETS — a subscription whose FIRST payment is free still asks for the card.
 *
 * A 100% first-payment coupon brings the order to ₪0, and WooCommerce skips the
 * payment step for a ₪0 order: no payment methods, no PayPlus page, the order is
 * simply completed. For a subscription that is the wrong outcome — the shopper
 * takes the first cycle and there is no card to charge the second one to.
 *
 * So a cart (and an unpaid order) that holds a LETS subscription is told it
 * still needs payment. The LETS gateway then opens PayPlus on a card CHECK page:
 * the card is verified and saved, nothing is charged today, and the SaaS
 * activates the subscription with a ₪0 first cycle and the next one at the
 * subscription price (WooGatewaySessionController / WooGatewayFinalizer).
 *
 * Both checkouts read these two filters: the classic checkout asks the cart,
 * the Checkout block (Store API) asks the cart for the payment step and the
 * order when it processes. Orders with no subscription, and orders that do cost
 * something, are left exactly as WooCommerce decided.
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The order line meta a LETS subscription line carries (class-lets-subscriptions.php). */
define('LETS_FREE_FIRST_CYCLE_LINE_META', '_lets_subscription');

/**
 * Does this order hold a LETS subscription line? Read from the order's own lines —
 * by the time an order exists, the cart hook that marked them has run.
 *
 * @param WC_Order $order
 */
function lets_payplus_order_has_subscription($order)
{
    if (! is_object($order) || ! method_exists($order, 'get_items')) {
        return false;
    }

    foreach ($order->get_items() as $item) {
        if (method_exists($item, 'get_meta') && ! empty($item->get_meta(LETS_FREE_FIRST_CYCLE_LINE_META))) {
            return true;
        }
    }

    return false;
}

// A ₪0 cart with a subscription still goes through the payment step.
add_filter('woocommerce_cart_needs_payment', function ($needs_payment, $cart = null) {
    if ($needs_payment || ! function_exists('lets_payplus_cart_has_subscription')) {
        return $needs_payment;
    }

    return lets_payplus_cart_has_subscription();
}, 20, 2);

// …and so does the ₪0 order it becomes, until it is paid.
add_filter('woocommerce_order_needs_payment', function ($needs_payment, $order = null, $valid_statuses = array()) {
    if ($needs_payment || ! is_object($order) || ! method_exists($order, 'get_total')) {
        return $needs_payment;
    }

    // Only the ₪0 case. An order that costs something was answered by WooCommerce.
    if ((float) $order->get_total() > 0) {
        return $needs_payment;
    }

    // A paid (processing / completed) order no longer needs anything.
    if (! empty($valid_statuses) && ! $order->has_status($valid_statuses)) {
        return false;
    }

    return lets_payplus_order_has_subscription($order);
}, 20, 3);
