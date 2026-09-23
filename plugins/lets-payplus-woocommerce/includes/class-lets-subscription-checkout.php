<?php
/**
 * LETS — the two rules a cart with a SUBSCRIPTION in it has to obey at checkout.
 *
 *   1. It is paid on the LETS rail, and on no other. PayPal, Bit, bank transfer,
 *      cash on delivery — every one of them takes this cycle's money perfectly
 *      well and leaves NOTHING to charge the next one with. The subscription
 *      would be created, go active, and fail at its first renewal, while the
 *      shopper believes they subscribed. There is no recovery from that which
 *      does not involve asking them for their card again.
 *
 *      Enforced twice, because a hidden button is a hint and not a rule: the
 *      gateway is removed from the list, AND the order is refused if one of the
 *      others arrives anyway (a stale checkout page, a saved session).
 *
 *   2. The subscription terms are ACCEPTED, explicitly, by a tick nobody
 *      pre-ticked. A recurring charge the customer did not knowingly agree to is
 *      the one dispute a merchant cannot win, and "they completed the checkout"
 *      is not the same as "they agreed to be billed every month". The acceptance
 *      is stamped on the order with its own timestamp and the text they saw, so
 *      the evidence outlives a later edit of the terms page.
 *
 * Both rules apply to the classic checkout AND to the Checkout block, which
 * posts to the Store API where none of the classic hooks fire. The plugin
 * learned that the hard way with the one-subscription-per-customer rule, which
 * for a while was enforced on exactly one of the two.
 *
 * Off by default, both of them: a store that has not been told which page holds
 * its terms must not start blocking its own checkout.
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** Restrict a subscription cart to the LETS gateway ("1"/"0"). Default ON — it is a money rule. */
define('LETS_SUB_GATEWAY_LOCK_OPT', 'lets_payplus_subscription_gateway_lock');

/** Require the terms tick ("1"/"0"). Default OFF until a terms page is set. */
define('LETS_SUB_TERMS_OPT', 'lets_payplus_subscription_terms');

/** Where the terms live — a URL the merchant pastes in. */
define('LETS_SUB_TERMS_URL_OPT', 'lets_payplus_subscription_terms_url');

/** The merchant's own wording for the tick, when they want their own. */
define('LETS_SUB_TERMS_TEXT_OPT', 'lets_payplus_subscription_terms_text');

/** The checkbox's field name (classic) and the order meta that records acceptance. */
define('LETS_SUB_TERMS_FIELD', 'lets_subscription_terms');

define('LETS_SUB_TERMS_META', '_lets_subscription_terms_accepted');

/** The block checkout's namespaced id for the same tick. */
define('LETS_SUB_TERMS_BLOCK_ID', 'lets-payplus/subscription-terms');

/** The gateway that may take a subscription's money. */
define('LETS_SUB_GATEWAY_ID', 'lets_payplus');

/* -------------------------------------------------------------------------
 * 0. Does this cart hold a subscription?
 * ---------------------------------------------------------------------- */

/**
 * Read the CART, the same way every other rule in this plugin reads it.
 *
 * Not the order's line meta: that is written by a hook which may run after this
 * one, and a rule that depends on hook order is a rule that fails silently.
 */
function lets_payplus_cart_has_subscription()
{
    if (! function_exists('WC') || ! WC()->cart) {
        return false;
    }

    foreach (WC()->cart->get_cart() as $item) {
        if (! empty($item['_lets_subscription'])) {
            return true;
        }
    }

    return false;
}

/* -------------------------------------------------------------------------
 * 1. Only the LETS rail may take a subscription's money
 * ---------------------------------------------------------------------- */

function lets_payplus_subscription_gateway_lock_enabled()
{
    return '1' === get_option(LETS_SUB_GATEWAY_LOCK_OPT, '1');
}

add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (! is_array($gateways) || is_admin() || ! lets_payplus_subscription_gateway_lock_enabled()) {
        return $gateways;
    }

    if (! lets_payplus_cart_has_subscription()) {
        return $gateways;
    }

    // Keep ONLY the LETS gateway. Naming the others one by one (paypal, cod…)
    // would leave the next one somebody installs quietly able to break a
    // subscription, and the rule is not about PayPal — it is about what can
    // still be charged a month from now.
    $kept = array();
    foreach ($gateways as $id => $gateway) {
        if (LETS_SUB_GATEWAY_ID === $id) {
            $kept[$id] = $gateway;
        }
    }

    /*
     * If the LETS gateway is not available (not configured, or unavailable for
     * this cart), hand back what we were given rather than an EMPTY list. An
     * empty list is a checkout with no way to pay and no explanation — worse
     * than the problem this rule exists to prevent, and the refusal below still
     * stops the order from completing on the wrong rail.
     */
    return array() !== $kept ? $kept : $gateways;
}, 20, 1);

/** The same refusal on the CLASSIC checkout, for an order that arrives anyway. */
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (! lets_payplus_subscription_gateway_lock_enabled() || ! lets_payplus_cart_has_subscription()) {
        return;
    }

    $method = isset($data['payment_method']) ? (string) $data['payment_method'] : '';

    if ('' !== $method && LETS_SUB_GATEWAY_ID !== $method) {
        $errors->add('lets_subscription_gateway', lets_payplus_subscription_gateway_notice());
    }
}, 20, 2);

function lets_payplus_subscription_gateway_notice()
{
    return lets_payplus_address_is_he()
        ? 'מנוי בסל נגבה בכרטיס אשראי בלבד, כדי שנוכל לחייב את המחזורים הבאים. בחרו באמצעי התשלום של החנות כדי להמשיך.'
        : __('A subscription in the basket is paid by card only, so the following cycles can be charged. Choose the store payment method to continue.', 'lets-payplus');
}

/* -------------------------------------------------------------------------
 * 2. The subscription terms have to be ticked
 * ---------------------------------------------------------------------- */

function lets_payplus_subscription_terms_url()
{
    return trim((string) get_option(LETS_SUB_TERMS_URL_OPT, ''));
}

/** On only when it is switched on AND there is something to link to. */
function lets_payplus_subscription_terms_required()
{
    return '1' === get_option(LETS_SUB_TERMS_OPT, '0') && '' !== lets_payplus_subscription_terms_url();
}

/**
 * The sentence beside the tick, as HTML with the link already in it.
 *
 * The merchant's own wording wins when they wrote one; `{link}` in it becomes
 * the anchor, so they can phrase the sentence however they like without having
 * to write the markup.
 */
function lets_payplus_subscription_terms_label()
{
    $url = lets_payplus_subscription_terms_url();
    $he  = lets_payplus_address_is_he();

    $anchor = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
        . esc_html($he ? 'תנאי המנוי' : __('the subscription terms', 'lets-payplus'))
        . '</a>';

    $custom = trim((string) get_option(LETS_SUB_TERMS_TEXT_OPT, ''));

    if ('' !== $custom) {
        return str_replace('{link}', $anchor, esc_html($custom));
    }

    return $he
        ? 'קראתי ואני מאשר/ת את ' . $anchor . ' — החיוב יחזור על עצמו עד לביטול.'
        : sprintf(
            /* translators: %s: link to the subscription terms. */
            __('I have read and accept %s — billing repeats until cancelled.', 'lets-payplus'),
            $anchor
        );
}

/** Classic: render the tick, unticked, right before the place-order button. */
add_action('woocommerce_review_order_before_submit', function () {
    if (! lets_payplus_subscription_terms_required() || ! lets_payplus_cart_has_subscription()) {
        return;
    }

    woocommerce_form_field(LETS_SUB_TERMS_FIELD, array(
        'type'     => 'checkbox',
        'class'    => array('form-row', 'validate-required', 'lets-subscription-terms'),
        'label'    => lets_payplus_subscription_terms_label(),
        // Never pre-ticked. A box the shopper did not touch is not consent, and
        // a merchant who needs it as evidence needs it to have been an ACT.
        'required' => true,
        'default'  => 0,
    ), '');
}, 10);

/** Classic: refuse without it. */
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (! lets_payplus_subscription_terms_required() || ! lets_payplus_cart_has_subscription()) {
        return;
    }

    $ticked = ! empty($_POST[LETS_SUB_TERMS_FIELD]); // phpcs:ignore WordPress.Security.NonceVerification

    if (! $ticked) {
        $errors->add('lets_subscription_terms', lets_payplus_subscription_terms_notice());
    }
}, 20, 2);

function lets_payplus_subscription_terms_notice()
{
    return lets_payplus_address_is_he()
        ? 'יש לאשר את תנאי המנוי כדי להמשיך.'
        : __('Please accept the subscription terms to continue.', 'lets-payplus');
}

/** Block checkout: the same tick, registered as an additional field (WC 8.9+). */
add_action('woocommerce_init', function () {
    if (! lets_payplus_subscription_terms_required() || ! function_exists('woocommerce_register_additional_checkout_field')) {
        return;
    }

    woocommerce_register_additional_checkout_field(array(
        'id'       => LETS_SUB_TERMS_BLOCK_ID,
        // The block API renders the label as TEXT, so the link cannot live in it.
        // The terms URL goes in the description instead, where it is at least
        // one tap away — and the classic checkout keeps the inline anchor.
        'label'    => wp_strip_all_tags(lets_payplus_subscription_terms_label()),
        'location' => 'order',
        'type'     => 'checkbox',
        'required' => false, // enforced below, only when the cart needs it
    ));
});

/**
 * Block checkout: enforce the tick where WooCommerce HANDS US THE VALUE.
 *
 * Deliberately not by reading the order's meta. The additional-fields API stores
 * a registered field under a key of its own devising, and a rule built on a
 * GUESS at that key does not fail quietly here — it fails closed, on every
 * order: the tick would read as empty however hard the shopper ticked it, and
 * the whole checkout would refuse. This hook is given the value, so there is
 * nothing to guess.
 *
 * `required` is false on the registration for a different reason: the block API
 * would then ask for it on EVERY order, and a store selling one subscription
 * among a hundred ordinary products must not make every shopper accept
 * subscription terms.
 *
 * Both hook names are attached — WooCommerce renamed this action between the
 * versions that ship the API. The one that does not exist never fires.
 */
foreach (array('woocommerce_validate_additional_field', 'woocommerce_blocks_validate_additional_field') as $lets_validate_hook) {
    add_action($lets_validate_hook, function ($errors, $field_key, $field_value) {
        if (LETS_SUB_TERMS_BLOCK_ID !== $field_key || ! is_object($errors)) {
            return;
        }

        if (! lets_payplus_subscription_terms_required() || ! lets_payplus_cart_has_subscription()) {
            return; // not this basket's rule
        }

        if (empty($field_value)) {
            $errors->add('lets_subscription_terms', wp_strip_all_tags(lets_payplus_subscription_terms_notice()));
        }
    }, 10, 3);
}

/**
 * Block checkout: record the acceptance, and refuse a wrong rail.
 *
 * The acceptance is stamped WITHOUT re-reading the tick: the order does not
 * exist unless the validation above let it through, so by here they ticked it.
 * Trusting that is not laxity — it is refusing to ask the same question twice
 * through a channel that might answer it wrongly.
 */
add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order) {
    if (! is_object($order) || ! lets_payplus_cart_has_subscription()) {
        return;
    }

    if (lets_payplus_subscription_terms_required()) {
        lets_payplus_subscription_terms_stamp($order);
    }

    // The gateway rule, for a block order that arrived on the wrong rail.
    if (lets_payplus_subscription_gateway_lock_enabled()) {
        $method = (string) $order->get_payment_method();

        if ('' !== $method && LETS_SUB_GATEWAY_ID !== $method
            && class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'lets_subscription_gateway',
                wp_strip_all_tags(lets_payplus_subscription_gateway_notice()),
                400
            );
        }
    }
}, 30, 1);

/** Classic: record the acceptance on the order once it is being created. */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (! is_object($order) || ! lets_payplus_subscription_terms_required() || ! lets_payplus_cart_has_subscription()) {
        return;
    }

    if (! empty($_POST[LETS_SUB_TERMS_FIELD])) { // phpcs:ignore WordPress.Security.NonceVerification
        lets_payplus_subscription_terms_stamp($order);
    }
}, 20, 2);

/**
 * WHAT they accepted, WHEN, and from WHERE — not merely "yes".
 *
 * The terms page can be edited tomorrow; the sentence beside the tick can be
 * rewritten. Evidence that says only "accepted" is evidence that a customer can
 * answer with "accepted what?". So the wording and the URL are frozen onto the
 * order as they were at the moment of the tick.
 */
function lets_payplus_subscription_terms_stamp($order)
{
    $order->update_meta_data(LETS_SUB_TERMS_META, array(
        'accepted_at' => gmdate('c'),
        'url'         => lets_payplus_subscription_terms_url(),
        'text'        => wp_strip_all_tags(lets_payplus_subscription_terms_label()),
        'ip'          => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
    ));

    $order->add_order_note(
        lets_payplus_address_is_he()
            ? 'הלקוח אישר את תנאי המנוי בקופה.'
            : __('The customer accepted the subscription terms at checkout.', 'lets-payplus')
    );
}

/** Show the acceptance on the order screen, where a merchant answering a dispute looks. */
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    if (! is_object($order)) {
        return;
    }

    $accepted = $order->get_meta(LETS_SUB_TERMS_META);

    if (! is_array($accepted) || empty($accepted['accepted_at'])) {
        return;
    }

    $when = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $accepted['accepted_at']);

    echo '<p><strong>'
        . esc_html(lets_payplus_address_is_he() ? 'תנאי המנוי אושרו' : __('Subscription terms accepted', 'lets-payplus'))
        . ':</strong> ' . esc_html($when);

    if (! empty($accepted['url'])) {
        echo ' — <a href="' . esc_url($accepted['url']) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html(lets_payplus_address_is_he() ? 'התנאים' : __('the terms', 'lets-payplus'))
            . '</a>';
    }

    echo '</p>';
}, 11, 1);
