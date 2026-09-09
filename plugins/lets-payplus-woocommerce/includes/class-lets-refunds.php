<?php

/**
 * Refunds pressed inside WooCommerce.
 *
 * A merchant standing in their WP dashboard refunds an order there, because that
 * is where they are. Until now that money went back with no PayPlus refund, no
 * ledger adjustment and no credit note: the shopper was made whole by WooCommerce
 * alone, and the merchant's books never heard about it.
 *
 * TWO PATHS, and the difference is who moves the money:
 *
 *   1. THE GATEWAY'S OWN REFUND. When the order was paid through LETS, WooCommerce
 *      calls the gateway's process_refund() and waits for an answer. That answer
 *      comes from PayPlus, via the SaaS, and WooCommerce writes its own refund
 *      record only if we say yes. @see lets_payplus_gateway_process_refund()
 *
 *   2. EVERY OTHER REFUND. Another gateway's, or an offline one the merchant
 *      recorded by hand. The money is already gone; the SaaS only needs to know,
 *      so its ledger and its paperwork can catch up.
 *
 * THE MIRROR SENDS A RUNNING TOTAL, NOT ONE REFUND'S AMOUNT. `woocommerce_order_refunded`
 * fires for path 1 as well, and a retried request fires it again — so an endpoint
 * fed "this refund's amount" would count the same money twice and tell a merchant
 * their customer was refunded double. The SaaS records the DELTA against what it
 * already knows, and every duplicate collapses to nothing by construction.
 *
 * @package Lets_PayPlus
 */

defined('ABSPATH') || exit;

/**
 * The refund a merchant pressed on a LETS-paid order.
 *
 * Returns TRUE only when PayPlus actually gave the money back — WooCommerce
 * writes its refund record from this, and a true on a refusal would leave the
 * store claiming a refund that never happened.
 *
 * @param  int         $order_id
 * @param  float|null  $amount
 * @param  string      $reason
 * @return bool|WP_Error
 */
function lets_payplus_gateway_process_refund($order_id, $amount = null, $reason = '')
{
    $order = wc_get_order($order_id);
    if (! $order) {
        return new WP_Error('lets_refund_no_order', __('That order could not be loaded.', 'lets-payplus'));
    }

    $amount = $amount === null ? (float) $order->get_total() : (float) $amount;
    if ($amount <= 0) {
        return new WP_Error('lets_refund_amount', __('Enter an amount to refund.', 'lets-payplus'));
    }

    $result = lets_payplus_signed_post(
        '/api/woocommerce/orders/' . rawurlencode((string) $order_id) . '/refund',
        array(
            'amount' => round($amount, 2),
            'reason' => (string) $reason,
        )
    );

    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        $body = is_array($data) && isset($data['body']) && is_array($data['body']) ? $data['body'] : array();

        // The SaaS's own reason when it has one — "the gateway refused the
        // refund" is something a merchant can act on; "HTTP 422" is not.
        $message = ! empty($body['reason'])
            ? sprintf(
                /* translators: %s: a short reason code from LETS. */
                __('LETS could not refund this order (%s).', 'lets-payplus'),
                (string) $body['reason']
            )
            : $result->get_error_message();

        lets_payplus_log_event($message, 'refunds', 'error');

        return new WP_Error('lets_refund_failed', $message);
    }

    if (empty($result['ok'])) {
        return new WP_Error(
            'lets_refund_failed',
            __('LETS did not refund this order. Nothing was returned to the customer.', 'lets-payplus')
        );
    }

    $order->add_order_note(sprintf(
        /* translators: %s: the refunded amount, formatted. */
        __('LETS refunded %s through PayPlus.', 'lets-payplus'),
        wc_price((float) $result['refunded'], array('currency' => $order->get_currency()))
    ));

    return true;
}

/**
 * Mirror a refund WooCommerce made on its own.
 *
 * Skipped for LETS-paid orders: those go through process_refund() above, which
 * has already told the SaaS, and reporting them here as well is how the same
 * money gets counted twice. (The SaaS's delta guard catches it too — this is the
 * cheaper of the two walls, not the only one.)
 *
 * Fire-and-forget: a merchant refunding an order must never be blocked, or shown
 * an error, because a SaaS endpoint was slow.
 *
 * @param  int  $order_id
 * @param  int  $refund_id
 * @return void
 */
function lets_payplus_refunds_on_order_refunded($order_id, $refund_id)
{
    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    if ($order->get_payment_method() === LETS_PAYPLUS_GATEWAY_ID) {
        return;
    }

    // Nothing to mirror for an order LETS has never heard of. A site can have
    // thousands of ordinary orders, and reporting every refund on all of them
    // would be a request per refund for no benefit.
    if (! lets_payplus_refunds_is_known_order($order)) {
        return;
    }

    lets_payplus_signed_post(
        '/api/woocommerce/orders/' . rawurlencode((string) $order_id) . '/refunded',
        array(
            // The RUNNING TOTAL — see the file docblock.
            'total_refunded' => round((float) $order->get_total_refunded(), 2),
            'reason' => lets_payplus_refunds_reason($refund_id),
        )
    );
}

/**
 * Does LETS have any business with this order? True when it started a plan, or
 * when LETS issued its document (the `all_orders` scope).
 *
 * @param  WC_Order  $order
 * @return bool
 */
function lets_payplus_refunds_is_known_order(WC_Order $order)
{
    if ('' !== (string) $order->get_meta(LETS_PAYPLUS_PLAN_META)) {
        return true;
    }

    return '' !== (string) $order->get_meta(LETS_PAYPLUS_INVOICE_ID_META);
}

/**
 * The merchant's own words on the refund, if they left any.
 *
 * @param  int  $refund_id
 * @return string
 */
function lets_payplus_refunds_reason($refund_id)
{
    $refund = wc_get_order($refund_id);

    return $refund instanceof WC_Order_Refund ? (string) $refund->get_reason() : '';
}

add_action('woocommerce_order_refunded', 'lets_payplus_refunds_on_order_refunded', 20, 2);
