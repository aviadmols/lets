<?php
/**
 * LETS — refund this order, from the WooCommerce order screen.
 *
 * WooCommerce has its own Refund button, and for an order paid through the LETS
 * gateway it now works (the gateway declares `refunds` and delegates to us). But
 * it is missing for the orders that matter most here: a DEPOSIT or INSTALLMENTS
 * order is paid on the PayPlus page, so WooCommerce records a payment method it
 * has no refund handler for, greys the API-refund path out, and offers only
 * "refund manually" — a bookkeeping entry that returns nobody's money. The money
 * for those orders is in the LETS ledger and is perfectly refundable; WooCommerce
 * just has no way to ask.
 *
 * So this box asks. It shows what LETS says is still refundable, takes a full or
 * partial amount, and:
 *
 *   1. asks the SaaS to move the money (PayPlus, the ledger, the credit note);
 *   2. only if that succeeded, writes WooCommerce's OWN refund record here with
 *      `wc_create_refund()` — the canonical API, which restocks and fires the
 *      hooks WooCommerce expects.
 *
 * STEP 2 IS DELIBERATELY LOCAL. The SaaS knows how to write the store record
 * itself (it does exactly that when the refund starts in the LETS admin), but
 * calling back into WooCommerce from inside a request WooCommerce is already
 * blocking on is a round trip WP → SaaS → WP: on a small host with few PHP
 * workers that deadlocks until it times out. Writing it here costs nothing and
 * cannot.
 *
 * Loads AFTER the signer and after class-lets-refunds.php.
 *
 * @package Lets_PayPlus
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===
/** admin-post action name; also the nonce action. */
define('LETS_PAYPLUS_REFUND_ACTION', 'lets_payplus_refund_order');

/** Where the box's outcome is carried back to the order screen. */
define('LETS_PAYPLUS_REFUND_NOTICE', 'lets_payplus_refund_notice');

/** How long the refundable figure is cached per order (seconds). */
define('LETS_PAYPLUS_REFUND_STATE_TTL', 60);

// ---------------------------------------------------------------------------
// The box
// ---------------------------------------------------------------------------

add_action('add_meta_boxes', 'lets_payplus_refund_register_metabox');

/** Register on whichever order screen this store runs (HPOS or classic). */
function lets_payplus_refund_register_metabox()
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
        'lets_payplus_order_refund',
        lets_payplus_is_he() ? 'LETS — זיכוי ההזמנה' : 'LETS — Refund this order',
        'lets_payplus_render_refund_metabox',
        $screen,
        'side',
        'default'
    );
}

/**
 * @param  WP_Post|WC_Order  $post_or_order  WP_Post on the classic screen, WC_Order on HPOS
 */
function lets_payplus_render_refund_metabox($post_or_order)
{
    $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
    if (! $order instanceof WC_Order) {
        return;
    }

    lets_payplus_metabox_styles();
    lets_payplus_refund_styles();
    $he = lets_payplus_is_he();

    if (lets_payplus_connection() === null) {
        echo lets_payplus_mb_card(
            'neutral',
            '·',
            $he ? 'לא מחובר' : 'Not connected',
            $he ? 'חברו את החנות ל-LETS כדי לזכות מכאן.' : 'Connect this store to LETS to refund from here.'
        );

        return;
    }

    $state = lets_payplus_refund_state($order);

    if ($state === null) {
        echo lets_payplus_mb_card(
            'neutral',
            '—',
            $he ? 'אין מה לזכות' : 'Nothing to refund',
            $he
                ? 'ל-LETS אין חיוב פתוח על ההזמנה הזו — או שלא שולם בה דבר דרכנו, או שהיא כבר זוכתה במלואה.'
                : 'LETS has no open charge on this order — either nothing was paid through it, or it has already been fully refunded.'
        );

        return;
    }

    echo lets_payplus_mb_card(
        'ok',
        '₪',
        $he ? 'ניתן לזכות' : 'Refundable',
        lets_payplus_refund_form($order, $state, $he),
        '',
        true
    );
}

/**
 * The form. Deliberately a plain POST to admin-post: no JavaScript, so it works
 * on every host and every WordPress, and the browser's own "are you sure" on a
 * re-submit is one more thing between a merchant and a second refund.
 *
 * @param  array{refundable: float, currency: string, has_plan: bool}  $state
 */
function lets_payplus_refund_form(WC_Order $order, array $state, $he)
{
    $max = number_format($state['refundable'], 2, '.', '');
    $formatted = wc_price($state['refundable'], array('currency' => $state['currency']));

    $html = '<p class="lets-mb__price">' . wp_kses_post($formatted) . '</p>';

    // A subscription is not money: refunding a cycle does not end the
    // arrangement, and a merchant should not learn that next month.
    if (! empty($state['has_plan'])) {
        $html .= '<p class="lets-mb__hint">'
            . esc_html($he
                ? 'להזמנה הזו מקושר מנוי. זיכוי לא מבטל אותו — לביטול, פתחו את ההזמנה ב-LETS.'
                : 'A subscription belongs to this order. A refund does not end it — to cancel, open the order in LETS.')
            . '</p>';
    }

    $html .= '<form class="lets-refund" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $html .= '<input type="hidden" name="action" value="' . esc_attr(LETS_PAYPLUS_REFUND_ACTION) . '">';
    $html .= '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '">';
    $html .= wp_nonce_field(LETS_PAYPLUS_REFUND_ACTION, '_wpnonce', true, false);

    $html .= '<p class="lets-refund__row">'
        . '<label class="lets-refund__label" for="lets-refund-amount">'
        . esc_html($he ? 'סכום לזיכוי' : 'Amount to refund')
        . '</label>'
        . '<input class="lets-refund__amount" type="number" step="0.01" min="0.01"'
        . ' max="' . esc_attr($max) . '" id="lets-refund-amount" name="amount"'
        . ' value="' . esc_attr($max) . '" required>'
        . '</p>';

    $html .= '<p class="lets-refund__row">'
        . '<label class="lets-refund__label" for="lets-refund-reason">'
        . esc_html($he ? 'סיבה' : 'Reason')
        . '</label>'
        . '<input class="lets-refund__reason" type="text" maxlength="255"'
        . ' id="lets-refund-reason" name="reason">'
        . '</p>';

    $html .= '<p class="lets-refund__row lets-refund__row--check">'
        . '<label><input type="checkbox" name="restock" value="1"> '
        . esc_html($he ? 'החזרת הפריטים למלאי' : 'Return the items to stock')
        . '</label>'
        . '</p>';

    $html .= '<p class="lets-refund__row">'
        . '<button type="submit" class="button button-primary">'
        . esc_html($he ? 'זיכוי דרך LETS' : 'Refund through LETS')
        . '</button>'
        . '</p>';

    $html .= '<p class="lets-mb__hint">'
        . esc_html($he
            ? 'הכסף חוזר דרך PayPlus, וחשבונית הזיכוי מופקת לפי ההגדרות שלכם. לא ניתן לבטל.'
            : 'The money goes back through PayPlus and the credit note follows your settings. This cannot be undone.')
        . '</p>';

    $html .= '</form>';

    return $html;
}

// ---------------------------------------------------------------------------
// The action
// ---------------------------------------------------------------------------

add_action('admin_post_' . LETS_PAYPLUS_REFUND_ACTION, 'lets_payplus_refund_handle');

/**
 * Move the money, then write WooCommerce's own record.
 *
 * THE ORDER MATTERS. A WooCommerce refund record written before the money moved
 * is a store telling a merchant their customer was refunded when nobody was.
 */
function lets_payplus_refund_handle()
{
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

    if (! current_user_can('edit_shop_orders') || ! $order_id) {
        wp_die(esc_html__('You are not allowed to refund this order.', 'lets-payplus'), '', array('response' => 403));
    }

    check_admin_referer(LETS_PAYPLUS_REFUND_ACTION);

    $order = wc_get_order($order_id);
    if (! $order instanceof WC_Order) {
        wp_die(esc_html__('That order could not be loaded.', 'lets-payplus'), '', array('response' => 404));
    }

    $amount = isset($_POST['amount']) ? round((float) wp_unslash($_POST['amount']), 2) : 0.0;
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
    $restock = ! empty($_POST['restock']);

    if ($amount <= 0) {
        lets_payplus_refund_redirect($order, 'error', __('Enter an amount to refund.', 'lets-payplus'));
    }

    // 1. The money. The SaaS owns PayPlus, the ledger and the credit note.
    $result = lets_payplus_signed_post(
        '/api/woocommerce/orders/' . rawurlencode((string) $order_id) . '/refund',
        array('amount' => $amount, 'reason' => $reason)
    );

    if (is_wp_error($result) || empty($result['ok'])) {
        $data = is_wp_error($result) ? $result->get_error_data() : array();
        $body = is_array($data) && isset($data['body']) && is_array($data['body']) ? $data['body'] : array();
        $code = ! empty($body['reason']) ? (string) $body['reason'] : '';

        lets_payplus_refund_redirect(
            $order,
            'error',
            $code !== ''
                ? sprintf(
                    /* translators: %s: a short reason code from LETS. */
                    __('LETS did not refund this order (%s). Nothing was returned to the customer.', 'lets-payplus'),
                    $code
                )
                : __('LETS did not refund this order. Nothing was returned to the customer.', 'lets-payplus')
        );
    }

    $refunded = round((float) $result['refunded'], 2);

    // 2. WooCommerce's own record — written only now that money has moved.
    //    wc_create_refund() is the canonical API: it restocks, recalculates the
    //    order's totals and fires the hooks a theme or another plugin listens for.
    $refund = wc_create_refund(array(
        'order_id' => $order_id,
        'amount' => $refunded,
        'reason' => $reason,
        'restock_items' => $restock,
        // FALSE: the money already went back through PayPlus. True would ask the
        // order's own gateway to send it a second time.
        'refund_payment' => false,
    ));

    if (is_wp_error($refund)) {
        // The customer HAS their money; only WooCommerce's own record is missing.
        // Say so plainly and leave a note on the order — silently reporting
        // success would hide a refund the store does not know it made.
        $order->add_order_note(sprintf(
            /* translators: %s: the refunded amount, formatted. */
            __('LETS refunded %s through PayPlus, but this order\'s refund record could not be written. Add it by hand.', 'lets-payplus'),
            wp_strip_all_tags(wc_price($refunded, array('currency' => $order->get_currency())))
        ));

        lets_payplus_refund_redirect(
            $order,
            'warning',
            __('The customer was refunded, but this order could not record it. See the order notes.', 'lets-payplus')
        );
    }

    delete_transient(lets_payplus_refund_state_key($order_id));

    lets_payplus_refund_redirect(
        $order,
        'success',
        sprintf(
            /* translators: %s: the refunded amount, formatted. */
            __('Refunded %s through PayPlus.', 'lets-payplus'),
            wp_strip_all_tags(wc_price($refunded, array('currency' => $order->get_currency())))
        )
    );
}

/** Carry the outcome back to the order screen and stop. Never returns. */
function lets_payplus_refund_redirect(WC_Order $order, $level, $message)
{
    set_transient(
        LETS_PAYPLUS_REFUND_NOTICE . '_' . get_current_user_id(),
        array('level' => $level, 'message' => $message),
        60
    );

    wp_safe_redirect($order->get_edit_order_url());
    exit;
}

add_action('admin_notices', 'lets_payplus_refund_admin_notice');

/** Show (once) whatever the last refund attempt had to say. */
function lets_payplus_refund_admin_notice()
{
    $key = LETS_PAYPLUS_REFUND_NOTICE . '_' . get_current_user_id();
    $notice = get_transient($key);

    if (! is_array($notice) || empty($notice['message'])) {
        return;
    }

    delete_transient($key);

    $class = in_array($notice['level'], array('success', 'warning', 'error'), true)
        ? 'notice-' . $notice['level']
        : 'notice-info';

    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
        . esc_html((string) $notice['message'])
        . '</p></div>';
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/**
 * What LETS says is still refundable on this order, or null when nothing is.
 *
 * Cached briefly: an order screen renders on every save and every tab, and the
 * figure only changes when money moves. Fails CLOSED — a SaaS hiccup shows "no
 * connection" rather than a stale ceiling somebody could refund against.
 *
 * @return array{refundable: float, currency: string, has_plan: bool}|null
 */
function lets_payplus_refund_state(WC_Order $order)
{
    $key = lets_payplus_refund_state_key($order->get_id());
    $cached = get_transient($key);

    if (is_array($cached)) {
        return $cached === array() ? null : $cached;
    }

    $resp = lets_payplus_signed_post(
        '/api/woocommerce/orders/' . rawurlencode((string) $order->get_id()) . '/refund-state',
        array()
    );

    if (is_wp_error($resp) || empty($resp['ok']) || ! isset($resp['state']) || ! is_array($resp['state'])) {
        set_transient($key, array(), LETS_PAYPLUS_REFUND_STATE_TTL);

        return null;
    }

    $refundable = round((float) ($resp['state']['refundable'] ?? 0), 2);

    if ($refundable <= 0) {
        set_transient($key, array(), LETS_PAYPLUS_REFUND_STATE_TTL);

        return null;
    }

    $state = array(
        'refundable' => $refundable,
        'currency' => (string) ($resp['state']['currency'] ?? $order->get_currency()),
        'has_plan' => ! empty($resp['state']['has_plan']),
    );

    set_transient($key, $state, LETS_PAYPLUS_REFUND_STATE_TTL);

    return $state;
}

/** @param int $order_id */
function lets_payplus_refund_state_key($order_id)
{
    return 'lets_pp_refund_state_' . (int) $order_id;
}

/** The box's own few rules, printed once. */
function lets_payplus_refund_styles()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
.lets-refund{margin-top:10px}
.lets-refund__row{margin:0 0 8px}
.lets-refund__row--check{font-size:12.5px}
.lets-refund__label{display:block;font-size:12px;color:#50575e;margin-bottom:2px}
.lets-refund__amount,.lets-refund__reason{width:100%}
</style>
    <?php
}
