<?php
/**
 * LETS — "who is the receipt for?" at checkout.
 *
 * A shopper pays for something that is not for them, or not for them alone: a
 * company reimbursing them, a gift, a parent paying for a child's membership.
 * The buyer is the buyer — but the DOCUMENT has to be made out to whoever will
 * file it, and a merchant otherwise finds that out by email a day later and
 * issues a credit note to fix a name.
 *
 * So one optional field at checkout. When it is filled, LETS makes the document
 * out to it; when it is empty nothing changes at all.
 *
 * WHAT IT DOES NOT DO. It never replaces the buyer's own name on the order. The
 * order records who paid — that is what a chargeback, a refund and a support
 * call are answered from — and only the accounting document is addressed
 * elsewhere. The two are different questions and this plugin keeps them apart.
 *
 * BOTH CHECKOUTS. The classic checkout gets the field through
 * `woocommerce_checkout_fields`; a store on the Checkout BLOCK posts to the
 * Store API instead, where that filter never runs, so the field is also
 * registered through WooCommerce's additional-fields API when the installed
 * version has it (8.9+). On an older store with a block checkout the field
 * simply does not appear — nothing breaks, and nothing silently half-works.
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The on/off option ("1"/"0"). Default OFF: a new field at checkout is the merchant's choice. */
define('LETS_RECEIPT_NAME_OPT', 'lets_payplus_receipt_name');

/** Order meta + the classic field name. */
define('LETS_RECEIPT_NAME_META', '_lets_receipt_name');

/** The block checkout's namespaced id for the same field. */
define('LETS_RECEIPT_NAME_BLOCK_ID', 'lets-payplus/receipt-name');

/** A name, not an essay. Matches the SaaS's own clamp on the field. */
define('LETS_RECEIPT_NAME_MAX', 120);

/** Is the field switched on for this store? */
function lets_payplus_receipt_name_enabled()
{
    return '1' === get_option(LETS_RECEIPT_NAME_OPT, '0');
}

function lets_payplus_receipt_name_label()
{
    return lets_payplus_address_is_he() ? 'הקבלה על שם' : __('Issue the receipt to', 'lets-payplus');
}

function lets_payplus_receipt_name_help()
{
    return lets_payplus_address_is_he()
        ? 'לא חובה. השאירו ריק והקבלה תצא על שמכם.'
        : __('Optional. Leave empty and the receipt is issued in your own name.', 'lets-payplus');
}

/* -------------------------------------------------------------------------
 * 1. The classic checkout
 * ---------------------------------------------------------------------- */

add_filter('woocommerce_checkout_fields', function ($fields) {
    if (! lets_payplus_receipt_name_enabled()) {
        return $fields;
    }

    $fields['billing'][LETS_RECEIPT_NAME_META] = array(
        'label'        => lets_payplus_receipt_name_label(),
        'description'  => lets_payplus_receipt_name_help(),
        'required'     => false,
        'class'        => array('form-row-wide'),
        'priority'     => 125,
        'maxlength'    => LETS_RECEIPT_NAME_MAX,
        'autocomplete' => 'off',
    );

    return $fields;
});

/** Classic: keep what they typed on the order. */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (! lets_payplus_receipt_name_enabled() || ! is_object($order)) {
        return;
    }

    $name = isset($data[LETS_RECEIPT_NAME_META]) ? $data[LETS_RECEIPT_NAME_META] : '';
    $name = lets_payplus_receipt_name_clean($name);

    if ('' !== $name) {
        $order->update_meta_data(LETS_RECEIPT_NAME_META, $name);
    }
}, 20, 2);

/* -------------------------------------------------------------------------
 * 2. The block checkout (WooCommerce 8.9+)
 * ---------------------------------------------------------------------- */

add_action('woocommerce_init', function () {
    if (! lets_payplus_receipt_name_enabled() || ! function_exists('woocommerce_register_additional_checkout_field')) {
        return;
    }

    woocommerce_register_additional_checkout_field(array(
        'id'         => LETS_RECEIPT_NAME_BLOCK_ID,
        'label'      => lets_payplus_receipt_name_label(),
        'location'   => 'order',
        'type'       => 'text',
        'required'   => false,
        'attributes' => array('maxLength' => LETS_RECEIPT_NAME_MAX),
    ));
});

/**
 * Block: catch the value where WooCommerce hands it over, and park it for the
 * order hook below.
 *
 * Not by reading the order's meta afterwards: the additional-fields API stores a
 * registered field under a key of its own devising, and a guess at that key
 * would read as empty forever — the field would appear to work and quietly
 * collect nothing. This hook is GIVEN the value, so there is nothing to guess.
 *
 * Both hook names are attached; WooCommerce renamed this action between the
 * versions that ship the API, and the one that does not exist never fires.
 */
foreach (array('woocommerce_validate_additional_field', 'woocommerce_blocks_validate_additional_field') as $lets_receipt_hook) {
    add_action($lets_receipt_hook, function ($errors, $field_key, $field_value) {
        if (LETS_RECEIPT_NAME_BLOCK_ID !== $field_key) {
            return;
        }

        $GLOBALS['lets_payplus_receipt_name_value'] = lets_payplus_receipt_name_clean($field_value);
    }, 10, 3);
}

/** Block: write what the validator saw onto the ONE meta key this plugin reads. */
add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order) {
    if (! lets_payplus_receipt_name_enabled() || ! is_object($order)) {
        return;
    }

    $name = isset($GLOBALS['lets_payplus_receipt_name_value'])
        ? (string) $GLOBALS['lets_payplus_receipt_name_value']
        : '';

    if ('' !== $name) {
        $order->update_meta_data(LETS_RECEIPT_NAME_META, $name);
    }

    unset($GLOBALS['lets_payplus_receipt_name_value']);
}, 20, 1);

/* -------------------------------------------------------------------------
 * 3. Where the merchant sees it
 * ---------------------------------------------------------------------- */

add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    if (! is_object($order)) {
        return;
    }

    $name = lets_payplus_receipt_name_clean($order->get_meta(LETS_RECEIPT_NAME_META));

    if ('' === $name) {
        return;
    }

    echo '<p><strong>' . esc_html(lets_payplus_receipt_name_label()) . ':</strong> ' . esc_html($name) . '</p>';
}, 10, 1);

/** Trim, strip tags, and cap. A name on a tax document is text and nothing else. */
function lets_payplus_receipt_name_clean($value)
{
    $value = wp_strip_all_tags(wp_unslash((string) $value));
    $value = trim(preg_replace('/\s+/u', ' ', $value));

    return function_exists('mb_substr')
        ? mb_substr($value, 0, LETS_RECEIPT_NAME_MAX)
        : substr($value, 0, LETS_RECEIPT_NAME_MAX);
}

/** What the invoicing report sends to LETS, or '' when the shopper said nothing. */
function lets_payplus_receipt_name_for_order($order)
{
    if (! is_object($order) || ! method_exists($order, 'get_meta')) {
        return '';
    }

    return lets_payplus_receipt_name_clean($order->get_meta(LETS_RECEIPT_NAME_META));
}
