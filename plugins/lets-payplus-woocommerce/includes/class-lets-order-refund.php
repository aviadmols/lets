<?php
/**
 * Refund or cancel this order, from the WooCommerce order screen.
 *
 * WooCommerce has its own Refund button, and for an order paid through our
 * gateway it now works. But it is missing for the orders that matter most here:
 * a DEPOSIT or INSTALLMENTS order is paid on the PayPlus page, so WooCommerce
 * records a payment method it has no refund handler for, greys the API-refund
 * path out, and offers only "refund manually" — a bookkeeping entry that returns
 * nobody's money. The money for those orders is on the PayPlus side and is
 * perfectly refundable; WooCommerce just has no way to ask.
 *
 * So this box asks. It shows what is still refundable, takes a full or partial
 * amount, optionally cancels the whole order, and:
 *
 *   1. asks the SaaS to move the money (PayPlus, the ledger, the credit note,
 *      and — when cancelling — the subscription the order started);
 *   2. only if that succeeded, writes WooCommerce's OWN refund record here with
 *      `wc_create_refund()` — the canonical API, which restocks and fires the
 *      hooks WooCommerce expects — and cancels the order if asked.
 *
 * STEP 2 IS DELIBERATELY LOCAL. The SaaS knows how to write the store record
 * itself (it does exactly that when the refund starts in the app), but calling
 * back into WooCommerce from inside a request WooCommerce is already blocking on
 * is a round trip WP → SaaS → WP: on a small host with few PHP workers that
 * deadlocks until it times out. Writing it here costs nothing and cannot.
 *
 * THERE IS NO <form> IN THE BOX. The order screen IS a form, browsers silently
 * drop a nested one, and the first version of this box therefore rendered a
 * button that quietly submitted WooCommerce's order-save instead — pressing it
 * did nothing at all. The fields carry ids and no names (so an order save never
 * sees them) and the button builds a top-level form in JS and submits that.
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

/** Order statuses there is no point offering to cancel. */
define('LETS_PAYPLUS_REFUND_CLOSED_STATUSES', array('cancelled', 'refunded'));

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
        lets_payplus_is_he() ? 'זיכוי או ביטול' : 'Refund or cancel',
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
            $he ? 'החנות אינה מחוברת, ולכן אי אפשר לזכות מכאן.' : 'This store is not connected, so refunds cannot be made here.'
        );

        return;
    }

    $state = lets_payplus_refund_state($order);
    $refundable = $state === null ? 0.0 : (float) $state['refundable'];
    $cancellable = ! in_array($order->get_status(), LETS_PAYPLUS_REFUND_CLOSED_STATUSES, true);

    // Nothing to refund AND nothing to cancel — say so and offer no controls.
    if ($refundable <= 0 && ! $cancellable) {
        echo lets_payplus_mb_card(
            'neutral',
            '—',
            $he ? 'אין מה לעשות' : 'Nothing to do',
            $he
                ? 'ההזמנה סגורה ואין עליה חיוב פתוח.'
                : 'This order is closed and has no open charge.'
        );

        return;
    }

    echo lets_payplus_mb_card(
        $refundable > 0 ? 'ok' : 'neutral',
        $refundable > 0 ? '₪' : '—',
        $refundable > 0
            ? ($he ? 'ניתן לזכות' : 'Refundable')
            : ($he ? 'אין מה לזכות' : 'Nothing to refund'),
        lets_payplus_refund_controls($order, $state, $cancellable, $he),
        '',
        true
    );
}

/**
 * The controls.
 *
 * NO <form> and NO name attributes — see the file docblock. The ids are what the
 * script reads; without names, a merchant pressing WooCommerce's own "Update"
 * button never posts any of this by accident.
 *
 * @param  array{refundable: float, currency: string, has_plan: bool}|null  $state
 * @param  bool  $cancellable
 * @param  bool  $he
 * @return string
 */
function lets_payplus_refund_controls(WC_Order $order, $state, $cancellable, $he)
{
    $refundable = $state === null ? 0.0 : (float) $state['refundable'];
    $has_plan = $state !== null && ! empty($state['has_plan']);
    $currency = $state === null ? $order->get_currency() : (string) $state['currency'];

    $html = '';

    if ($refundable > 0) {
        $html .= '<p class="lets-mb__price">'
            . wp_kses_post(wc_price($refundable, array('currency' => $currency)))
            . '</p>';
    } else {
        $html .= '<p class="lets-mb__text">'
            . esc_html($he
                ? 'אין חיוב פתוח על ההזמנה הזו — אפשר עדיין לבטל אותה.'
                : 'This order has no open charge — it can still be cancelled.')
            . '</p>';
    }

    $html .= '<div class="lets-refund" id="lets-refund">';

    if ($refundable > 0) {
        $max = number_format($refundable, 2, '.', '');

        $html .= '<p class="lets-refund__row" id="lets-refund-amount-row">'
            . '<label class="lets-refund__label" for="lets-refund-amount">'
            . esc_html($he ? 'סכום לזיכוי' : 'Amount to refund')
            . '</label>'
            . '<input class="lets-refund__amount" type="number" step="0.01" min="0.01"'
            . ' max="' . esc_attr($max) . '" id="lets-refund-amount" value="' . esc_attr($max) . '">'
            . '</p>';
    }

    $html .= '<p class="lets-refund__row">'
        . '<label class="lets-refund__label" for="lets-refund-reason">'
        . esc_html($he ? 'סיבה' : 'Reason')
        . '</label>'
        . '<input class="lets-refund__reason" type="text" maxlength="255" id="lets-refund-reason">'
        . '</p>';

    if ($refundable > 0) {
        $html .= '<p class="lets-refund__row lets-refund__row--check">'
            . '<label><input type="checkbox" id="lets-refund-restock"> '
            . esc_html($he ? 'החזרת הפריטים למלאי' : 'Return the items to stock')
            . '</label>'
            . '</p>';
    }

    if ($cancellable) {
        $html .= '<p class="lets-refund__row lets-refund__row--check">'
            . '<label><input type="checkbox" id="lets-refund-cancel"' . ($refundable > 0 ? '' : ' checked') . '> '
            . esc_html($he ? 'ביטול ההזמנה כולה' : 'Cancel the whole order')
            . '</label>'
            . '</p>';

        // What cancelling ADDS, said before it is ticked rather than after.
        $html .= '<p class="lets-mb__hint" id="lets-refund-cancel-note" hidden>'
            . esc_html($he
                ? ($has_plan
                    ? 'כל מה שנותר יוזכר, ההזמנה תסומן כמבוטלת, והמנוי שהתחיל ממנה ייעצר.'
                    : 'כל מה שנותר יוזכר וההזמנה תסומן כמבוטלת.')
                : ($has_plan
                    ? 'Everything still refundable goes back, the order is marked cancelled, and the subscription it started is stopped.'
                    : 'Everything still refundable goes back and the order is marked cancelled.'))
            . '</p>';
    }

    if ($has_plan && $cancellable) {
        // A refund is not a cancellation, and a merchant should not learn that
        // next month when the subscription bills again.
        $html .= '<p class="lets-mb__hint" id="lets-refund-plan-note">'
            . esc_html($he
                ? 'להזמנה הזו מקושר מנוי. זיכוי לבדו לא עוצר אותו.'
                : 'A subscription belongs to this order. A refund alone does not stop it.')
            . '</p>';
    }

    $html .= '<p class="lets-refund__row">'
        . '<button type="button" class="button button-primary" id="lets-refund-submit">'
        . esc_html($he ? 'ביצוע' : 'Apply')
        . '</button>'
        . '</p>';

    $html .= '<p class="lets-mb__hint">'
        . esc_html($he
            ? 'הכסף חוזר דרך PayPlus וחשבונית הזיכוי מופקת לפי ההגדרות שלכם. לא ניתן לבטל.'
            : 'The money goes back through PayPlus and the credit note follows your settings. This cannot be undone.')
        . '</p>';

    $html .= '</div>';

    $html .= lets_payplus_refund_script($order, $he);

    return $html;
}

/**
 * The one piece of script the box needs: build a TOP-LEVEL form and submit it.
 *
 * The order screen is itself a form and browsers drop a nested one, so there is
 * no markup answer here — the form has to be created outside it. It is built at
 * click time, appended to <body>, and submitted; nothing is left in the DOM for
 * WooCommerce's own save to pick up.
 *
 * @param  bool  $he
 * @return string
 */
function lets_payplus_refund_script(WC_Order $order, $he)
{
    $config = array(
        'action' => admin_url('admin-post.php'),
        'name' => LETS_PAYPLUS_REFUND_ACTION,
        'nonce' => wp_create_nonce(LETS_PAYPLUS_REFUND_ACTION),
        'order' => (string) $order->get_id(),
        'confirm' => $he
            ? 'לבצע? הפעולה מחזירה כסף ללקוח ואי אפשר לבטל אותה.'
            : 'Apply? This returns money to the customer and cannot be undone.',
    );

    ob_start();
    ?>
<script>
(function () {
    var cfg = <?php echo wp_json_encode($config); ?>;
    var box = document.getElementById('lets-refund');
    if (! box) { return; }

    var cancel = document.getElementById('lets-refund-cancel');
    var amountRow = document.getElementById('lets-refund-amount-row');
    var cancelNote = document.getElementById('lets-refund-cancel-note');
    var planNote = document.getElementById('lets-refund-plan-note');

    // Cancelling means "everything still refundable", so the amount field has
    // nothing to say — hiding it is how the box avoids promising a partial
    // refund it would then ignore.
    function sync() {
        var on = cancel && cancel.checked;
        if (amountRow) { amountRow.hidden = on; }
        if (cancelNote) { cancelNote.hidden = ! on; }
        if (planNote) { planNote.hidden = on; }
    }

    if (cancel) { cancel.addEventListener('change', sync); }
    sync();

    document.getElementById('lets-refund-submit').addEventListener('click', function () {
        if (! window.confirm(cfg.confirm)) { return; }

        var amountField = document.getElementById('lets-refund-amount');
        var reason = document.getElementById('lets-refund-reason');
        var restock = document.getElementById('lets-refund-restock');

        var fields = {
            action: cfg.name,
            _wpnonce: cfg.nonce,
            order_id: cfg.order,
            amount: (cancel && cancel.checked) || ! amountField ? '' : amountField.value,
            reason: reason ? reason.value : '',
            restock: restock && restock.checked ? '1' : '',
            cancel: cancel && cancel.checked ? '1' : ''
        };

        // A top-level form: the order screen is already one, and a nested form
        // is dropped by the browser.
        var form = document.createElement('form');
        form.method = 'post';
        form.action = cfg.action;
        form.style.display = 'none';

        Object.keys(fields).forEach(function (key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    });
}());
</script>
    <?php

    return (string) ob_get_clean();
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

    $cancel = ! empty($_POST['cancel']);
    $restock = ! empty($_POST['restock']);
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
    $amount = isset($_POST['amount']) ? round((float) wp_unslash($_POST['amount']), 2) : 0.0;

    // A cancellation names no amount — it means everything still refundable.
    if (! $cancel && $amount <= 0) {
        lets_payplus_refund_redirect($order, 'error', __('Enter an amount to refund.', 'lets-payplus'));
    }

    // 1. The money, and the subscription. Ours to move, so ask the app.
    $result = lets_payplus_signed_post(
        '/api/woocommerce/orders/' . rawurlencode((string) $order_id) . '/refund',
        array(
            'amount' => $cancel ? 0 : $amount,
            'reason' => $reason,
            'cancel' => $cancel ? 1 : 0,
        )
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
                    /* translators: %s: a short reason code. */
                    __('The refund did not go through (%s). Nothing was returned to the customer.', 'lets-payplus'),
                    $code
                )
                : __('The refund did not go through. Nothing was returned to the customer.', 'lets-payplus')
        );
    }

    $refunded = round((float) $result['refunded'], 2);
    $problem = '';

    // 2. WooCommerce's own record — written only now that money has moved.
    //    wc_create_refund() is the canonical API: it restocks, recalculates the
    //    order's totals and fires the hooks a theme or another plugin listens for.
    if ($refunded > 0) {
        $refund = wc_create_refund(array(
            'order_id' => $order_id,
            'amount' => $refunded,
            'reason' => $reason,
            'restock_items' => $restock,
            // FALSE: the money already went back through PayPlus. True would ask
            // the order's own gateway to send it a second time.
            'refund_payment' => false,
        ));

        if (is_wp_error($refund)) {
            // The customer HAS their money; only WooCommerce's own record is
            // missing. Say so plainly and leave a note — reporting success would
            // hide a refund the store does not know it made.
            $order->add_order_note(sprintf(
                /* translators: %s: the refunded amount, formatted. */
                __('%s was refunded through PayPlus, but this order\'s refund record could not be written. Add it by hand.', 'lets-payplus'),
                wp_strip_all_tags(wc_price($refunded, array('currency' => $order->get_currency())))
            ));

            $problem = __('The customer was refunded, but this order could not record it. See the order notes.', 'lets-payplus');
        }
    }

    // 3. The cancellation, after the refund — WooCommerce restocks on the move to
    //    `cancelled` too, and the refund above has already returned whatever the
    //    merchant asked for, so doing it the other way round returns it twice.
    if ($cancel && ! in_array($order->get_status(), LETS_PAYPLUS_REFUND_CLOSED_STATUSES, true)) {
        $order->update_status(
            'cancelled',
            $reason !== ''
                ? sprintf(
                    /* translators: %s: the merchant's reason. */
                    __('Cancelled: %s', 'lets-payplus'),
                    $reason
                )
                : __('Cancelled from the order screen.', 'lets-payplus')
        );
    }

    delete_transient(lets_payplus_refund_state_key($order_id));

    if ($problem !== '') {
        lets_payplus_refund_redirect($order, 'warning', $problem);
    }

    lets_payplus_refund_redirect($order, 'success', lets_payplus_refund_success_message($order, $refunded, $cancel));
}

/**
 * What actually happened, in one sentence.
 *
 * @param  float  $refunded
 * @param  bool   $cancel
 * @return string
 */
function lets_payplus_refund_success_message(WC_Order $order, $refunded, $cancel)
{
    $money = wp_strip_all_tags(wc_price($refunded, array('currency' => $order->get_currency())));

    if ($cancel && $refunded > 0) {
        /* translators: %s: the refunded amount, formatted. */
        return sprintf(__('Order cancelled and %s refunded through PayPlus.', 'lets-payplus'), $money);
    }

    if ($cancel) {
        return __('Order cancelled. Nothing was paid on it, so no money moved.', 'lets-payplus');
    }

    /* translators: %s: the refunded amount, formatted. */
    return sprintf(__('Refunded %s through PayPlus.', 'lets-payplus'), $money);
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

/** Show (once) whatever the last attempt had to say. */
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
 * What is still refundable on this order, or null when nothing is.
 *
 * Cached briefly: an order screen renders on every save and every tab, and the
 * figure only changes when money moves. Fails CLOSED — a hiccup shows no
 * refundable amount rather than a stale ceiling somebody could refund against.
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
