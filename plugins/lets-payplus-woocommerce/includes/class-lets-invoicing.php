<?php
/**
 * LETS — Green Invoice (Morning) documents for ALL site orders.
 *
 * LETS only ever sees orders that went through a LETS flow (a deposit plan, a
 * subscription, an upsell, or the PayPlus gateway). A merchant who wants an
 * accounting document for EVERY order on their site — including a bank transfer or a
 * cash-on-delivery order LETS never touched — needs this store to report the rest.
 *
 * The merchant configures scope + trigger statuses in the LETS dashboard, NOT here:
 * LETS is the single source of truth, and this file only caches the answer so the
 * order hook can decide cheaply. A `plans_only` store therefore costs zero HTTP calls
 * per order.
 *
 * Flow:
 *   woocommerce_order_status_changed  → is the new status one the merchant picked?
 *                                     → POST the order to LETS (server-side HMAC; the
 *                                       shopper's browser never holds the api_secret)
 *   POST /wp-json/lets-payplus/v1/notify {event: document_issued}
 *                                     → stamp the order meta + add an order note
 *
 * Double-issue walls, in order: an order already linked to a LETS plan is skipped
 * (the plan pipeline invoices it); an order already stamped with a document id is
 * skipped; and LETS itself keys every document to the order id.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Cached copy of the merchant's invoicing scope, so the order hook is cheap. */
define('LETS_PAYPLUS_INVOICING_TRANSIENT', 'lets_payplus_invoicing_settings');
define('LETS_PAYPLUS_INVOICING_TTL', 300); // seconds

/** Order meta: the issued document, so we never request a second one. */
define('LETS_PAYPLUS_INVOICE_ID_META', '_lets_invoice_document_id');
define('LETS_PAYPLUS_INVOICE_URL_META', '_lets_invoice_document_url');
define('LETS_PAYPLUS_INVOICE_NUMBER_META', '_lets_invoice_document_number');

/** Order meta stamped by LETS on plan orders (WooCommerceOrderStrategy). */
define('LETS_PAYPLUS_PLAN_META', 'lets_plan_public_id');

/** Order meta stamped by LETS naming what an order IS; 'gift_order' is not a sale. */
define('LETS_PAYPLUS_ORDER_ROLE_META', 'lets_order_role');
define('LETS_PAYPLUS_GIFT_ROLE', 'gift_order');

/** Hard cap on reported line items — LETS bounds this again server-side. */
define('LETS_PAYPLUS_INVOICE_MAX_LINES', 100);

/**
 * The merchant's invoicing scope, from LETS. Cached briefly; returns null when the
 * store is not connected or LETS could not be reached (in which case we do NOTHING —
 * failing closed here means a missing document, while failing open would mean an
 * unwanted tax document, which is far harder for a merchant to undo).
 */
function lets_payplus_invoicing_settings()
{
    /*
     * Wrapped, because this function runs inside `woocommerce_order_status_changed`
     * and on the customer's order-received page — and a fatal in a WordPress hook
     * does not fail the hook, it fails the whole REQUEST. A TypeError in here once
     * turned every order save and every thank-you page on a live store into a 500,
     * and took gift-order creation down with it.
     *
     * Nothing this function can fail at is worth a merchant's checkout. Null is
     * already the "do nothing" answer every caller understands, so any breakage
     * degrades to "no document was requested" — a missing document is a button
     * click; a broken store is lost orders.
     */
    try {
        $cached = get_transient(LETS_PAYPLUS_INVOICING_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        if (! lets_payplus_connection()) {
            return null;
        }

        $result = lets_payplus_signed_get('/api/woocommerce/invoicing-settings', array());

        if (is_wp_error($result) || empty($result['settings']) || ! is_array($result['settings'])) {
            return null;
        }

        set_transient(LETS_PAYPLUS_INVOICING_TRANSIENT, $result['settings'], LETS_PAYPLUS_INVOICING_TTL);

        return $result['settings'];
    } catch (\Throwable $e) {
        // Logged so it is findable, then swallowed so the page still renders.
        if (function_exists('lets_payplus_log_event')) {
            lets_payplus_log_event(
                sprintf('Could not read the invoicing settings: %s', $e->getMessage()),
                'invoicing',
                'error'
            );
        }

        return null;
    }
}

/** Drop the cached scope so the next order re-reads the merchant's current choice. */
function lets_payplus_invoicing_flush()
{
    delete_transient(LETS_PAYPLUS_INVOICING_TRANSIENT);
}

/**
 * The order hook. Fires on every status change; returns immediately unless the
 * merchant has invoicing on, in `all_orders` scope, and picked this status.
 */
add_action('woocommerce_order_status_changed', 'lets_payplus_invoicing_on_status_changed', 20, 4);

function lets_payplus_invoicing_on_status_changed($order_id, $old_status, $new_status, $order = null)
{
    $settings = lets_payplus_invoicing_settings();

    if (! is_array($settings) || empty($settings['enabled'])) {
        return;
    }
    if (! isset($settings['scope']) || 'all_orders' !== $settings['scope']) {
        return;
    }

    $statuses = isset($settings['trigger_statuses']) && is_array($settings['trigger_statuses'])
        ? $settings['trigger_statuses']
        : array();
    if (! in_array(lets_payplus_invoicing_normalise_status($new_status), $statuses, true)) {
        return;
    }

    if (! ($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
    }
    if (! ($order instanceof WC_Order)) {
        return;
    }

    // Wall 1: a LETS plan order is invoiced by the plan pipeline. Reporting it here
    // would ask for a SECOND document for money already declared once.
    if ('' !== (string) $order->get_meta(LETS_PAYPLUS_PLAN_META)) {
        return;
    }

    // Wall 1a: the same rule, asked of the CART LINE instead of the plan meta.
    //
    // That meta is stamped by the SaaS when it links the plan to the order, which
    // happens moments AFTER WooCommerce flips the order to paid — and this hook
    // runs on exactly that flip. On a real store the gap was five seconds, and in
    // it a subscription order was reported as a plain order: the shopper received
    // two tax invoices for one payment. The line meta is written at checkout,
    // before any of this, so it is the answer that is already true when asked.
    if (lets_payplus_invoicing_has_subscription_line($order)) {
        return;
    }

    // Wall 1b: a LETS loyalty GIFT. Nothing was sold and nothing was paid, so
    // there is no income to declare — a tax document here would report revenue the
    // merchant never received.
    if (LETS_PAYPLUS_GIFT_ROLE === (string) $order->get_meta(LETS_PAYPLUS_ORDER_ROLE_META)) {
        return;
    }

    // Wall 2: we already have a document for this order (a status flapping
    // processing → completed must not produce two).
    if ('' !== (string) $order->get_meta(LETS_PAYPLUS_INVOICE_ID_META)) {
        return;
    }

    lets_payplus_invoicing_report($order);
}

/**
 * Does this order sell a LETS subscription? Read from the line meta the cart
 * stamps at checkout (class-lets-subscriptions), so it is true from the moment
 * the order exists — unlike the plan meta, which the SaaS writes back later.
 *
 * @param  WC_Order  $order
 * @return bool
 */
function lets_payplus_invoicing_has_subscription_line(WC_Order $order)
{
    foreach ($order->get_items() as $item) {
        if (! is_object($item) || ! method_exists($item, 'get_meta')) {
            continue;
        }
        if ($item->get_meta('_lets_subscription')) {
            return true;
        }
    }

    return false;
}

/** Normalise "wc-processing" / " Processing " to the bare lowercase status. */
function lets_payplus_invoicing_normalise_status($status)
{
    $status = strtolower(trim((string) $status));

    return 0 === strpos($status, 'wc-') ? substr($status, 3) : $status;
}

/** POST one paid order to LETS. Never throws; a failure is logged for the merchant. */
function lets_payplus_invoicing_report(WC_Order $order)
{
    $result = lets_payplus_signed_post(
        '/api/woocommerce/orders/issue-document',
        lets_payplus_invoicing_order_body($order)
    );

    if (is_wp_error($result)) {
        lets_payplus_log_event(
            sprintf(
                /* translators: 1: order number, 2: error message. */
                __('Could not request an invoice for order %1$s: %2$s', 'lets-payplus'),
                $order->get_order_number(),
                $result->get_error_message()
            ),
            'invoicing',
            'error'
        );

        return;
    }

    // LETS may answer with an already-issued document (its own idempotency wall).
    // Stamp it now rather than waiting for a notify that will never come.
    if (! empty($result['document']) && is_array($result['document'])) {
        lets_payplus_invoicing_stamp_order($order, $result['document']);
    }
}

/**
 * The order, in the shape the LETS endpoint reads. Amounts are WooCommerce's own
 * totals — WooCommerce is the money truth for an order it processed itself.
 *
 * @return array
 */
function lets_payplus_invoicing_order_body(WC_Order $order)
{
    $lines = array();

    foreach (array_slice($order->get_items(), 0, LETS_PAYPLUS_INVOICE_MAX_LINES) as $item) {
        $quantity = max(1, (int) $item->get_quantity());
        // The line SUBTOTAL is the pre-discount price; get_total() is what the customer
        // actually pays for the line, which is what a document must declare.
        $line_total = (float) $order->get_line_total($item, true, true);

        $lines[] = array(
            'description'    => (string) $item->get_name(),
            'unit_price'     => round($line_total / $quantity, 2),
            'quantity'       => $quantity,
            'catalog_number' => lets_payplus_invoicing_sku($item),
        );
    }

    return array(
        'order_id'         => (string) $order->get_id(),
        'order_number'     => (string) $order->get_order_number(),
        'status'           => lets_payplus_invoicing_normalise_status($order->get_status()),
        // Report the LETS plan link when the order has one. LETS re-checks this
        // server-side (and looks the order up in its own plan table), but sending it
        // lets the cheap check win before any database work.
        LETS_PAYPLUS_PLAN_META => (string) $order->get_meta(LETS_PAYPLUS_PLAN_META),
        'total'            => round((float) $order->get_total(), 2),
        'currency'         => (string) $order->get_currency(),
        'customer_name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
        'customer_email'   => (string) $order->get_billing_email(),
        'customer_phone'   => (string) $order->get_billing_phone(),
        'payment_gateway'  => (string) $order->get_payment_method(),
        'lines'            => $lines,
    );
}

/** The product SKU behind a line item, or null. */
function lets_payplus_invoicing_sku($item)
{
    if (! method_exists($item, 'get_product')) {
        return null;
    }

    $product = $item->get_product();
    if (! $product || ! method_exists($product, 'get_sku')) {
        return null;
    }

    $sku = trim((string) $product->get_sku());

    return '' !== $sku ? $sku : null;
}

/**
 * Record an issued document on the order: meta (the double-issue wall) plus an order
 * note, so the merchant sees the document straight from the WooCommerce order screen.
 */
function lets_payplus_invoicing_stamp_order(WC_Order $order, array $document)
{
    $id = isset($document['id']) ? (string) $document['id'] : '';
    if ('' === $id) {
        return;
    }

    $number = isset($document['number']) ? (string) $document['number'] : '';
    $url    = isset($document['url']) ? (string) $document['url'] : '';

    $order->update_meta_data(LETS_PAYPLUS_INVOICE_ID_META, $id);
    $order->update_meta_data(LETS_PAYPLUS_INVOICE_NUMBER_META, $number);
    $order->update_meta_data(LETS_PAYPLUS_INVOICE_URL_META, $url);
    $order->save();

    $note = '' !== $url
        /* translators: 1: document number, 2: document URL. */
        ? sprintf(__('Invoice %1$s issued by Green Invoice: %2$s', 'lets-payplus'), $number !== '' ? $number : $id, $url)
        /* translators: %s: document number. */
        : sprintf(__('Invoice %s issued by Green Invoice.', 'lets-payplus'), $number !== '' ? $number : $id);

    $order->add_order_note($note);
}
