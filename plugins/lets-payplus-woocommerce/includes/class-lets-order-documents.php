<?php
/**
 * LETS — invoices & receipts on the WooCommerce ORDER (W20).
 *
 * Two surfaces, one data source:
 *
 *   ADMIN — a side metabox on the order-edit screen (classic post table AND HPOS)
 *   showing every Green Invoice document issued for this order, number + open link.
 *   Fast path: the meta the notify leg already stamps. Fallback: a signed read of
 *   POST /api/woocommerce/orders/documents — which also covers documents issued
 *   through the LETS PLAN pipeline (deposits / subscription cycles), where no
 *   status-change hook ever fired on this store. A fallback hit BACKFILLS the
 *   stamp so future renders are free and the double-issue wall is repaired.
 *
 *   CUSTOMER — the document link on the order-received page and My Account →
 *   view order, gated on the merchant's attach_to_order setting (the flag the
 *   invoicing-settings payload has always carried and nothing consumed). Renders
 *   STAMPED META ONLY — a shopper request never triggers a SaaS call.
 *
 * Loads AFTER class-lets-invoicing.php (uses its meta constants + stamp function)
 * and AFTER the signer (class-lets-product-widget.php).
 */

if (! defined('ABSPATH')) {
    exit;
}

// === CONSTANTS ===
define('LETS_PAYPLUS_ORDER_DOCS_TRANSIENT_PREFIX', 'lets_pp_order_docs_');
define('LETS_PAYPLUS_ORDER_DOCS_TTL', 300); // seconds — same cadence as the settings cache

// ---------------------------------------------------------------------------
// Admin metabox
// ---------------------------------------------------------------------------

add_action('add_meta_boxes', 'lets_payplus_order_docs_register_metabox');

/**
 * Register on whichever order screen this store runs: the HPOS orders page when
 * custom order tables are enabled, else the classic shop_order post screen.
 */
function lets_payplus_order_docs_register_metabox()
{
    $screen = 'shop_order';
    if (
        class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        && function_exists('wc_get_page_screen_id')
    ) {
        $screen = wc_get_page_screen_id('shop-order');
    }

    add_meta_box(
        'lets_payplus_order_documents',
        lets_payplus_is_he() ? 'LETS — חשבוניות וקבלות' : 'LETS — Invoices & receipts',
        'lets_payplus_render_order_docs_metabox',
        $screen,
        'side',
        'default'
    );
}

/**
 * Render the metabox. $post_or_order is WP_Post on the classic screen and
 * WC_Order on HPOS — normalise before anything else.
 */
function lets_payplus_render_order_docs_metabox($post_or_order)
{
    $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
    if (! $order instanceof WC_Order) {
        return;
    }

    lets_payplus_metabox_styles();
    $he = lets_payplus_is_he();

    if (lets_payplus_connection() === null) {
        echo lets_payplus_mb_card(
            'neutral',
            '·',
            $he ? 'לא מחובר' : 'Not connected',
            $he ? 'חברו את החנות ל-LETS כדי לראות מסמכי חשבונאות כאן.' : 'Connect this store to LETS to see accounting documents here.'
        );

        return;
    }

    $documents = lets_payplus_order_documents($order);

    if ($documents === array()) {
        echo lets_payplus_mb_card(
            'neutral',
            '—',
            $he ? 'אין מסמכים עדיין' : 'No documents yet',
            $he
                ? 'מסמך שיופק להזמנה הזו (חשבונית ירוקה) יופיע כאן.'
                : 'A document issued for this order (Green Invoice) will appear here.'
        );

        return;
    }

    $rows = '';
    foreach ($documents as $doc) {
        $label = $doc['number'] !== '' ? $doc['number'] : $doc['id'];
        $line = '<p class="lets-mb__text">';
        $line .= $doc['url'] !== ''
            ? '<a class="lets-mb__link" href="' . esc_url($doc['url']) . '" target="_blank" rel="noopener">' . esc_html($label) . ' ↗</a>'
            : esc_html($label);
        if ($doc['issued_at'] !== '') {
            $line .= ' <span class="lets-mb__muted">· ' . esc_html(date_i18n(get_option('date_format'), strtotime($doc['issued_at']))) . '</span>';
        }
        $line .= '</p>';
        $rows .= $line;
    }

    echo lets_payplus_mb_card(
        'ok',
        '✓',
        $he
            ? (count($documents) === 1 ? 'מסמך הופק' : 'מסמכים הופקו')
            : (count($documents) === 1 ? 'Document issued' : 'Documents issued'),
        $rows,
        '',
        true
    );
}

/**
 * The documents for one order, admin-side: stamped meta first (free), else a
 * transient-cached signed read of the SaaS — which is the only source that also
 * knows PLAN-pipeline documents (deposits / subscription cycles). A fallback hit
 * backfills the stamp so the next render takes the fast path.
 *
 * @return array<int, array{id:string, number:string, url:string, issued_at:string}>
 */
function lets_payplus_order_documents(WC_Order $order)
{
    // Fast path — the notify leg already stamped this order.
    $stamped_id = (string) $order->get_meta(LETS_PAYPLUS_INVOICE_ID_META);
    if ($stamped_id !== '') {
        return array(array(
            'id' => $stamped_id,
            'number' => (string) $order->get_meta(LETS_PAYPLUS_INVOICE_NUMBER_META),
            'url' => (string) $order->get_meta(LETS_PAYPLUS_INVOICE_URL_META),
            'issued_at' => '',
        ));
    }

    // Fallback — ask the SaaS (cached; an admin page render must not hammer it).
    $cache_key = LETS_PAYPLUS_ORDER_DOCS_TRANSIENT_PREFIX . $order->get_id();
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $resp = lets_payplus_signed_post('/api/woocommerce/orders/documents', array(
        'order_id' => (string) $order->get_id(),
    ));

    if (is_wp_error($resp) || empty($resp['ok'])) {
        // Fail-soft: cache the miss briefly so a SaaS hiccup doesn't turn every
        // admin page load into a retry storm.
        set_transient($cache_key, array(), LETS_PAYPLUS_ORDER_DOCS_TTL);

        return array();
    }

    $documents = array();
    foreach ((array) ($resp['documents'] ?? array()) as $doc) {
        if (! is_array($doc)) {
            continue;
        }
        $documents[] = array(
            'id' => isset($doc['id']) ? (string) $doc['id'] : '',
            'number' => isset($doc['number']) ? (string) $doc['number'] : '',
            'url' => isset($doc['url']) ? (string) $doc['url'] : '',
            'issued_at' => isset($doc['issued_at']) ? (string) $doc['issued_at'] : '',
        );
    }

    // Backfill the stamp from the first document: future renders take the fast
    // path, and the double-issue wall (meta presence) is repaired for orders that
    // predate the notify leg.
    if ($documents !== array() && $documents[0]['id'] !== '') {
        lets_payplus_invoicing_stamp_order($order, $documents[0]);
    }

    set_transient($cache_key, $documents, LETS_PAYPLUS_ORDER_DOCS_TTL);

    return $documents;
}

// ---------------------------------------------------------------------------
// Customer-facing link (order-received + My Account → view order)
// ---------------------------------------------------------------------------

add_action('woocommerce_order_details_after_order_table', 'lets_payplus_order_docs_customer_link');

/**
 * The shopper's copy of their receipt. The DOCUMENT itself is stamped meta only —
 * a shopper view never triggers the documents lookup — and it renders only when
 * the merchant opted into attach_to_order (the setting the invoicing-settings
 * payload always carried; this is its first consumer). The meta check runs FIRST,
 * so the (300s-cached, store-wide) settings read can only ever be triggered by an
 * order that actually has a document. A null settings read fails closed: no link.
 */
function lets_payplus_order_docs_customer_link($order)
{
    if (! $order instanceof WC_Order) {
        return;
    }

    $url = (string) $order->get_meta(LETS_PAYPLUS_INVOICE_URL_META);
    if ($url === '') {
        return;
    }

    $settings = lets_payplus_invoicing_settings();
    if (empty($settings['attach_to_order'])) {
        return;
    }

    $number = (string) $order->get_meta(LETS_PAYPLUS_INVOICE_NUMBER_META);
    $he = lets_payplus_is_he();
    $label = $he ? 'חשבונית / קבלה' : 'Invoice / receipt';
    $link_text = $number !== ''
        ? ($he ? 'צפייה במסמך ' . $number : 'View document ' . $number)
        : ($he ? 'צפייה במסמך' : 'View document');

    echo '<section class="lets-order-document">'
        . '<h2 class="woocommerce-column__title">' . esc_html($label) . '</h2>'
        . '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($link_text) . ' ↗</a></p>'
        . '</section>';
}

// ---------------------------------------------------------------------------
// Order HISTORY — the document beside each row
// ---------------------------------------------------------------------------

add_filter('woocommerce_my_account_my_orders_actions', 'lets_payplus_order_docs_list_action', 10, 2);

/**
 * A shopper looking for last month's receipt should not have to open the order
 * to find out whether there is one. The link goes in WooCommerce's own actions
 * column, so it inherits the table, the mobile stacking and the re-skin — and
 * a theme that renders its own orders table still gets it, because this is the
 * filter WooCommerce itself calls for that column.
 *
 * Reads STAMPED META ONLY. A history page lists many orders, and a lookup per
 * row would turn one page view into a fan-out of SaaS calls; an order whose
 * document has not been stamped yet simply shows no link, and the order page
 * (which reads a single order) remains the place where the fallback runs.
 *
 * @param array    $actions
 * @param WC_Order $order
 * @return array
 */
function lets_payplus_order_docs_list_action($actions, $order)
{
    if (! $order instanceof WC_Order) {
        return $actions;
    }

    $url = (string) $order->get_meta(LETS_PAYPLUS_INVOICE_URL_META);
    if ($url === '') {
        return $actions;
    }

    $settings = lets_payplus_invoicing_settings();
    if (empty($settings['attach_to_order'])) {
        return $actions;
    }

    $he = lets_payplus_is_he();
    $number = (string) $order->get_meta(LETS_PAYPLUS_INVOICE_NUMBER_META);

    /* The key becomes a class on the button, which is how the stylesheet can
       tell a document link from "view" and "pay" without a second hook.
       WooCommerce's own template prints no target/rel here, so the document
       opens in the same tab — the back button is the way back. */
    $actions['lets_invoice'] = array(
        'url' => $url,
        'name' => $he ? 'חשבונית' : 'Invoice',
        'aria-label' => $number !== ''
            ? sprintf($he ? 'צפייה במסמך %s' : 'View document %s', $number)
            : ($he ? 'צפייה בחשבונית' : 'View invoice'),
    );

    return $actions;
}
