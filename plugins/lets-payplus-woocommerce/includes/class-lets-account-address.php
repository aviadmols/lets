<?php

/**
 * LETS — the shopper's address, moved into My Account → account details.
 *
 * WooCommerce splits one question across two tabs: "account details" holds the
 * name and the email, "addresses" holds a landing page with two links, each
 * leading to a form. A shopper who wants to fix their street has to find the
 * tab, choose which of the two addresses, and only then type. So the BILLING
 * address — the one every Israeli store actually uses — moves up into the
 * details form as a fieldset of its own, saved by the SAME submit button, and
 * the addresses tab is left with the one thing that is genuinely separate: a
 * DIFFERENT shipping address. A store that has no separate shipping address at
 * all (shipping off, or "ship to billing only") loses the tab entirely, since
 * everything it held now lives one tab up.
 *
 * IT IS STILL WOOCOMMERCE'S ADDRESS. The fields come from
 * `WC()->countries->get_address_fields()` — so the Israeli field order this
 * plugin installs (city → street → building/apartment/floor/entrance) and any
 * other plugin's filters apply here exactly as they do at checkout — and the
 * save writes through `WC_Customer` and fires `woocommerce_customer_save_address`,
 * which is what LETS's own Timeline reporter and the phone index listen for.
 * Nothing downstream can tell the difference between this form and the one it
 * replaces.
 *
 * The name and the email are NOT repeated here: the details form above already
 * owns them. They are only copied onto an EMPTY billing name/email on save, so
 * a shopper who has never checked out still ends up with a complete record.
 */
defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The on/off option ("1"/"0"). Default ON. */
define('LETS_PROFILE_ADDRESS_OPT', 'lets_payplus_address_in_profile');

/** Billing keys the account-details form above already owns. */
define('LETS_PROFILE_ADDRESS_SKIP', 'billing_first_name,billing_last_name,billing_email');

/* -------------------------------------------------------------------------
 * 1. The toggle
 * ---------------------------------------------------------------------- */

function lets_payplus_profile_address_enabled()
{
    if (! function_exists('WC') || ! function_exists('woocommerce_form_field')) {
        return false;
    }

    return '0' !== get_option(LETS_PROFILE_ADDRESS_OPT, '1');
}

/**
 * Is there a SEPARATE shipping address to keep a tab for? With shipping off,
 * or with the store shipping to the billing address only, WooCommerce's
 * addresses tab holds nothing this form has not already taken.
 */
function lets_payplus_profile_shipping_available()
{
    if (function_exists('wc_shipping_enabled') && ! wc_shipping_enabled()) {
        return false;
    }

    return ! (function_exists('wc_ship_to_billing_address_only') && wc_ship_to_billing_address_only());
}

/* -------------------------------------------------------------------------
 * 2. The fields, inside the account-details form
 * ---------------------------------------------------------------------- */

/**
 * The billing address fields WooCommerce would have drawn on its own address
 * form, minus the three the details form above already asks for.
 *
 * Each entry carries its current `value`, because the country/state pair reads
 * the country's value to decide which states to offer.
 *
 * @param  int  $user_id
 * @return array<string, array>
 */
function lets_payplus_profile_address_fields($user_id)
{
    $customer = new WC_Customer($user_id);
    $country = $customer->get_billing_country('edit');

    $fields = WC()->countries->get_address_fields(
        $country ? $country : WC()->countries->get_base_country(),
        'billing_'
    );

    foreach (explode(',', LETS_PROFILE_ADDRESS_SKIP) as $skip) {
        unset($fields[$skip]);
    }

    foreach ($fields as $key => $field) {
        $fields[$key]['value'] = is_callable(array($customer, 'get_' . $key))
            ? $customer->{'get_' . $key}('edit')
            : $customer->get_meta($key, true);
    }

    return $fields;
}

add_action('woocommerce_edit_account_form', 'lets_payplus_profile_address_render');

function lets_payplus_profile_address_render()
{
    $user_id = get_current_user_id();

    if (! lets_payplus_profile_address_enabled() || $user_id <= 0) {
        return;
    }

    $fields = lets_payplus_profile_address_fields($user_id);
    if (array() === $fields) {
        return;
    }

    $he = function_exists('lets_payplus_address_is_he') && lets_payplus_address_is_he();

    echo '<fieldset class="lets-profile-address">';
    echo '<legend>' . esc_html($he ? 'הכתובת שלי' : __('My address', 'lets-payplus')) . '</legend>';

    if (lets_payplus_profile_shipping_available()) {
        echo '<p class="lets-profile-address__note">' . esc_html(
            $he
                ? 'כתובת משלוח אחרת נערכת בלשונית הכתובות.'
                : __('A different shipping address is edited in the addresses tab.', 'lets-payplus')
        ) . '</p>';
    }

    foreach ($fields as $key => $field) {
        // The state field asks which country was chosen, so it can offer that
        // country's states — exactly as WooCommerce's own template does.
        if (isset($field['country_field'], $fields[$field['country_field']])) {
            $field['country'] = wc_get_post_data_by_key(
                $field['country_field'],
                $fields[$field['country_field']]['value']
            );
        }

        woocommerce_form_field($key, $field, wc_get_post_data_by_key($key, $field['value']));
    }

    echo '</fieldset>';
}

/* -------------------------------------------------------------------------
 * 3. Validation — the same rules WooCommerce's own address form applies
 * ---------------------------------------------------------------------- */

add_action('woocommerce_save_account_details_errors', 'lets_payplus_profile_address_validate', 10, 2);

function lets_payplus_profile_address_validate($errors, $user)
{
    if (! lets_payplus_profile_address_enabled() || ! is_a($errors, 'WP_Error')) {
        return;
    }

    $user_id = isset($user->ID) ? (int) $user->ID : get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    $he = function_exists('lets_payplus_address_is_he') && lets_payplus_address_is_he();

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form's nonce before firing this.
    foreach (lets_payplus_profile_address_fields($user_id) as $key => $field) {
        if (! isset($_POST[$key])) {
            continue; // a field the form never drew is not a field the shopper skipped
        }

        $value = wc_clean(wp_unslash($_POST[$key]));
        $label = isset($field['label']) ? $field['label'] : $key;

        if ('' === $value) {
            if (! empty($field['required'])) {
                $errors->add($key . '_required', sprintf(
                    /* translators: %s: field label */
                    $he ? 'יש למלא את השדה "%s".' : __('%s is a required field.', 'lets-payplus'),
                    $label
                ));
            }

            continue;
        }

        $validate = isset($field['validate']) ? (array) $field['validate'] : array();

        if (in_array('postcode', $validate, true)) {
            $country = isset($_POST['billing_country']) ? wc_clean(wp_unslash($_POST['billing_country'])) : '';
            if (! WC_Validation::is_postcode(wc_format_postcode($value, $country), $country)) {
                $errors->add($key . '_validation', $he
                    ? 'המיקוד שהוזן אינו תקין.'
                    : __('Please enter a valid postcode / ZIP.', 'lets-payplus'));
            }
        }

        if (in_array('phone', $validate, true) && ! WC_Validation::is_phone($value)) {
            $errors->add($key . '_validation', sprintf(
                /* translators: %s: field label */
                $he ? 'המספר שהוזן בשדה "%s" אינו מספר טלפון תקין.' : __('%s is not a valid phone number.', 'lets-payplus'),
                $label
            ));
        }
    }

    // The city rule, said once — the same one the checkout enforces. NULL from
    // the registry means "we could not ask", and an outage never blocks a save.
    $city = isset($_POST['billing_city']) ? trim(wc_clean(wp_unslash($_POST['billing_city']))) : '';
    if ('' !== $city && lets_payplus_address_enabled()
        && false === lets_payplus_address_city_is_known($city)) {
        $errors->add('billing_city_unknown', lets_payplus_address_city_error());
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}

/* -------------------------------------------------------------------------
 * 4. The save — through WC_Customer, so nothing downstream notices the change
 * ---------------------------------------------------------------------- */

add_action('woocommerce_save_account_details', 'lets_payplus_profile_address_save');

function lets_payplus_profile_address_save($user_id)
{
    $user_id = (int) $user_id;

    if (! lets_payplus_profile_address_enabled() || $user_id <= 0) {
        return;
    }

    $fields = lets_payplus_profile_address_fields($user_id);
    if (array() === $fields) {
        return;
    }

    $customer = new WC_Customer($user_id);
    $touched = false;

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form's nonce before firing this.
    foreach ($fields as $key => $field) {
        if (! isset($_POST[$key]) && 'checkbox' !== (isset($field['type']) ? $field['type'] : 'text')) {
            continue;
        }

        $value = 'checkbox' === (isset($field['type']) ? $field['type'] : 'text')
            ? (int) isset($_POST[$key])
            : wc_clean(wp_unslash($_POST[$key]));

        // A postcode is STORED the way WooCommerce would have stored it — the
        // address form formats before it saves, and a search on it later has to
        // match what checkout writes.
        if (in_array('postcode', isset($field['validate']) ? (array) $field['validate'] : array(), true)) {
            $country = isset($_POST['billing_country']) ? wc_clean(wp_unslash($_POST['billing_country'])) : '';
            $value = wc_format_postcode($value, $country);
        }

        // The same last word other plugins already have at checkout.
        $value = apply_filters('woocommerce_process_myaccount_field_' . $key, $value);

        // A setter that refuses its value throws (WC_Data_Exception). One bad
        // field must leave the rest of the address saved, not white-screen the
        // account page — the validation above is what tells the shopper why.
        try {
            if (is_callable(array($customer, 'set_' . $key))) {
                $customer->{'set_' . $key}($value);
            } else {
                $customer->update_meta_data($key, $value);
            }
        } catch (Exception $e) {
            continue;
        }

        $touched = true;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    if (! $touched) {
        return;
    }

    // The details form above owns the name and the email; copy them down only
    // where the billing record is still blank, so a shopper who has never
    // checked out still ends up with one that is complete.
    if ('' === trim((string) $customer->get_billing_first_name('edit'))) {
        $customer->set_billing_first_name((string) get_user_meta($user_id, 'first_name', true));
        $customer->set_billing_last_name((string) get_user_meta($user_id, 'last_name', true));
    }

    if ('' === trim((string) $customer->get_billing_email('edit'))) {
        $account = get_userdata($user_id);
        if ($account && is_email($account->user_email)) {
            $customer->set_billing_email($account->user_email);
        }
    }

    $customer->save();

    // The one event everything else listens for — LETS's Timeline reporter, the
    // phone index, and any other plugin that watches an address change.
    do_action('woocommerce_customer_save_address', $user_id, 'billing');
}

/* -------------------------------------------------------------------------
 * 5. What is left of the addresses tab
 * ---------------------------------------------------------------------- */

/**
 * The addresses tab lists the SHIPPING address alone — billing has moved. A
 * store with no separate shipping address keeps its list untouched, because
 * the tab is about to disappear anyway (see the menu filter below).
 */
add_filter('woocommerce_my_account_get_addresses', 'lets_payplus_profile_address_list', 20, 2);

function lets_payplus_profile_address_list($addresses, $customer_id)
{
    if (! lets_payplus_profile_address_enabled() || ! isset($addresses['shipping'])) {
        return $addresses;
    }

    return array('shipping' => $addresses['shipping']);
}

/** With nothing but billing to show, the tab has no content left to promise. */
add_filter('woocommerce_account_menu_items', 'lets_payplus_profile_address_menu', 30);

function lets_payplus_profile_address_menu($items)
{
    if (! lets_payplus_profile_address_enabled() || lets_payplus_profile_shipping_available()) {
        return $items;
    }

    unset($items['edit-address']);

    return $items;
}

/**
 * A bookmark, an old email, or WooCommerce's own "billing address" link still
 * points at the form we retired. Send it to the one place that now edits it,
 * so the shopper never meets two forms for one address.
 *
 * GET only: a POST is WooCommerce's own address save, and it must be allowed
 * to run and redirect on its own terms.
 */
add_action('template_redirect', 'lets_payplus_profile_address_redirect', 20);

function lets_payplus_profile_address_redirect()
{
    global $wp;

    if (! lets_payplus_profile_address_enabled() || ! function_exists('is_account_page') || ! is_account_page()) {
        return;
    }

    if ('GET' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
        return;
    }

    $which = isset($wp->query_vars['edit-address']) ? sanitize_title((string) $wp->query_vars['edit-address']) : '';
    if ('' === $which || 'billing' !== wc_edit_address_i18n($which, true)) {
        return;
    }

    wp_safe_redirect(wc_get_endpoint_url('edit-account', '', wc_get_page_permalink('myaccount')));
    exit;
}
