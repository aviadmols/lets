<?php
/**
 * LETS — every paid order on this site, to the merchant's own webhook.
 *
 * The merchant adds webhook URLs in the LETS dashboard (Settings → Webhooks). On a
 * WooCommerce store they receive EVERY order — a PayPlus checkout, a bank transfer
 * another gateway took, a renewal order LETS created — because this file reports
 * each one to LETS, which signs it and delivers it (with retries) to every URL
 * listening to `order.paid`.
 *
 * Flow:
 *   woocommerce_order_status_changed → first time the order reaches a paid status?
 *                                     → POST it to LETS (server-side HMAC; the
 *                                       shopper's browser never holds the api_secret)
 *                                     → stamp the order so a later status flap
 *                                       (processing → completed) never reports twice
 *
 * A store with no webhook costs no calls: the decision inputs are fetched from LETS
 * and cached, exactly like the invoicing hook's. Nothing here may fail the order —
 * every path is wrapped and degrades to "not reported".
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Cached copy of "does anyone listen?", so the order hook is cheap. */
define('LETS_PAYPLUS_WEBHOOKS_TRANSIENT', 'lets_payplus_webhook_settings');
define('LETS_PAYPLUS_WEBHOOKS_TTL', 300); // seconds

/** Order meta: reported once, never twice. */
define('LETS_PAYPLUS_WEBHOOK_REPORTED_META', '_lets_webhook_reported_at');

/** Hard cap on reported line items — LETS bounds this again server-side. */
define('LETS_PAYPLUS_WEBHOOK_MAX_ITEMS', 100);

/** Address fields copied from the order (LETS reads the same list). */
define('LETS_PAYPLUS_WEBHOOK_ADDRESS_FIELDS', array(
    'first_name', 'last_name', 'company', 'address_1', 'address_2',
    'city', 'state', 'postcode', 'country', 'email', 'phone',
));

/**
 * Is any webhook listening, and on which statuses? Null when the store is not
 * connected or LETS could not be reached — the hook then does nothing.
 */
function lets_payplus_webhook_settings()
{
    try {
        $cached = get_transient(LETS_PAYPLUS_WEBHOOKS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        if (! lets_payplus_connection()) {
            return null;
        }

        $result = lets_payplus_signed_get('/api/woocommerce/webhook-settings', array());
        if (is_wp_error($result) || empty($result['settings']) || ! is_array($result['settings'])) {
            return null;
        }

        set_transient(LETS_PAYPLUS_WEBHOOKS_TRANSIENT, $result['settings'], LETS_PAYPLUS_WEBHOOKS_TTL);

        return $result['settings'];
    } catch (\Throwable $e) {
        if (function_exists('lets_payplus_log_event')) {
            lets_payplus_log_event(sprintf('Could not read the webhook settings: %s', $e->getMessage()), 'webhooks', 'error');
        }

        return null;
    }
}

/** Drop the cached answer so the next order re-reads it. */
function lets_payplus_webhook_flush()
{
    delete_transient(LETS_PAYPLUS_WEBHOOKS_TRANSIENT);
}
add_action('lets_payplus_settings_changed', 'lets_payplus_webhook_flush');

add_action('woocommerce_order_status_changed', 'lets_payplus_webhook_on_status_changed', 25, 4);

function lets_payplus_webhook_on_status_changed($order_id, $old_status, $new_status, $order = null)
{
    try {
        $settings = lets_payplus_webhook_settings();
        if (! is_array($settings) || empty($settings['enabled'])) {
            return;
        }

        $statuses = isset($settings['statuses']) && is_array($settings['statuses']) ? $settings['statuses'] : array();
        $status = strtolower(trim((string) $new_status));
        $status = 0 === strpos($status, 'wc-') ? substr($status, 3) : $status;
        if (! in_array($status, $statuses, true)) {
            return;
        }

        if (! ($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }
        if (! ($order instanceof WC_Order)) {
            return;
        }

        // Once per order: processing → completed is the same sale.
        if ('' !== (string) $order->get_meta(LETS_PAYPLUS_WEBHOOK_REPORTED_META)) {
            return;
        }

        lets_payplus_webhook_report($order);
    } catch (\Throwable $e) {
        if (function_exists('lets_payplus_log_event')) {
            lets_payplus_log_event(sprintf('Could not report order %s to the webhook: %s', $order_id, $e->getMessage()), 'webhooks', 'error');
        }
    }
}

/** POST one order to LETS. Never throws; a failure is logged for the merchant. */
function lets_payplus_webhook_report(WC_Order $order)
{
    $result = lets_payplus_signed_post('/api/woocommerce/orders/webhook', lets_payplus_webhook_order_body($order));

    if (is_wp_error($result)) {
        lets_payplus_log_event(
            sprintf(
                /* translators: 1: order number, 2: error message. */
                __('Could not send order %1$s to the webhook: %2$s', 'lets-payplus'),
                $order->get_order_number(),
                $result->get_error_message()
            ),
            'webhooks',
            'error'
        );

        return;
    }

    $order->update_meta_data(LETS_PAYPLUS_WEBHOOK_REPORTED_META, gmdate('c'));
    $order->save();
}

/**
 * The order, in the shape LETS reads (OrderWebhookController).
 *
 * @return array
 */
function lets_payplus_webhook_order_body(WC_Order $order)
{
    $after_sell_meta = defined('LETS_PAYPLUS_AFTER_SELL_ITEM_META') ? LETS_PAYPLUS_AFTER_SELL_ITEM_META : '_lets_after_sell';
    $items = array();

    foreach (array_slice($order->get_items(), 0, LETS_PAYPLUS_WEBHOOK_MAX_ITEMS) as $item) {
        if (! is_object($item) || ! method_exists($item, 'get_product_id')) {
            continue;
        }

        $product = method_exists($item, 'get_product') ? $item->get_product() : null;

        $items[] = array(
            'product_id'   => (string) $item->get_product_id(),
            'variation_id' => (string) $item->get_variation_id(),
            'sku'          => ($product && method_exists($product, 'get_sku')) ? (string) $product->get_sku() : '',
            'name'         => (string) $item->get_name(),
            'quantity'     => (int) $item->get_quantity(),
            'subtotal'     => round((float) $order->get_line_subtotal($item, true, true), 2),
            'total'        => round((float) $order->get_line_total($item, true, true), 2),
            'after_sell'   => '' !== (string) $item->get_meta($after_sell_meta),
        );
    }

    $paid = $order->get_date_paid();
    $created = $order->get_date_created();
    $role = defined('LETS_PAYPLUS_ORDER_ROLE_META') ? (string) $order->get_meta(LETS_PAYPLUS_ORDER_ROLE_META) : '';

    return array(
        'order_id'             => (string) $order->get_id(),
        'order_number'         => (string) $order->get_order_number(),
        // WHICH SITE is reporting: a staging copy holds the same key, and its
        // test orders must never reach the merchant's live systems.
        'site_url'             => home_url(),
        'status'               => (string) $order->get_status(),
        'role'                 => '' !== $role ? $role : 'checkout',
        'lets_plan_public_id'  => defined('LETS_PAYPLUS_PLAN_META') ? (string) $order->get_meta(LETS_PAYPLUS_PLAN_META) : '',
        'currency'             => (string) $order->get_currency(),
        'total'                => round((float) $order->get_total(), 2),
        'subtotal'             => round((float) $order->get_subtotal(), 2),
        'discount_total'       => round((float) $order->get_discount_total(), 2),
        'shipping_total'       => round((float) $order->get_shipping_total(), 2),
        'tax_total'            => round((float) $order->get_total_tax(), 2),
        'payment_method'       => (string) $order->get_payment_method(),
        'payment_method_title' => (string) $order->get_payment_method_title(),
        'transaction_id'       => (string) $order->get_transaction_id(),
        'customer_id'          => (string) $order->get_customer_id(),
        'customer_note'        => (string) $order->get_customer_note(),
        'date_created'         => $created ? $created->date('c') : '',
        'date_paid'            => $paid ? $paid->date('c') : '',
        'billing'              => lets_payplus_webhook_address($order, 'billing'),
        'shipping'             => lets_payplus_webhook_address($order, 'shipping'),
        'coupons'              => array_values(array_map('strval', $order->get_coupon_codes())),
        'items'                => $items,
    );
}

/** One address block, read through WooCommerce's own getters. */
function lets_payplus_webhook_address(WC_Order $order, $type)
{
    $address = array();

    foreach (LETS_PAYPLUS_WEBHOOK_ADDRESS_FIELDS as $field) {
        $getter = 'get_' . $type . '_' . $field;
        $address[$field] = method_exists($order, $getter) ? (string) $order->{$getter}() : '';
    }

    return $address;
}
