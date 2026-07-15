<?php
/**
 * LETS — PayPlus PAYMENT PAGE options on Settings → LETS.
 *
 * The merchant edits here, but LETS stores them per-shop and applies them to EVERY PayPlus page
 * the store can produce (normal checkout, deposit, subscription, the diagnostics probe) — so the
 * pages can never drift apart. We read/write over the signed API:
 *   GET  /api/woocommerce/checkout-settings
 *   POST /api/woocommerce/checkout-settings
 * The SaaS allow-lists and clamps every field; nothing here is trusted.
 *
 * HONEST SCOPE (verified against the PayPlus API reference):
 *   - PayPlus has NO iframe parameter. "Show as iframe" is a LOCAL display choice of this plugin
 *     (see the gateway's Display setting), not something PayPlus is told.
 *   - PayPlus has NO google_pay / apple_pay flags. Those wallets are enabled ON THE PAYMENT PAGE
 *     inside the PayPlus dashboard. We do not render toggles that would do nothing.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('LETS_PAYPLUS_PAGE_SETTINGS_TRANSIENT', 'lets_payplus_page_settings');

/** Human labels for the documented PayPlus charge methods. */
function lets_payplus_method_label($method)
{
    $labels = array(
        'credit-card' => __('Credit card', 'lets-payplus'),
        'bit'         => __('Bit', 'lets-payplus'),
        'multipass'   => __('Multipass', 'lets-payplus'),
        'paypal'      => __('PayPal', 'lets-payplus'),
        'praxell'     => __('Praxell', 'lets-payplus'),
        'valuecard'   => __('Valuecard', 'lets-payplus'),
        'verifone'    => __('Verifone', 'lets-payplus'),
    );

    return isset($labels[$method]) ? $labels[$method] : $method;
}

add_action('admin_post_lets_payplus_save_page_settings', function () {
    lets_payplus_diag_guard(); // manage_options + nonce (shared with the diagnostics screen)

    $body = array(
        'language_code'             => isset($_POST['language_code']) ? sanitize_text_field(wp_unslash($_POST['language_code'])) : 'he',
        'charge_default'            => isset($_POST['charge_default']) ? sanitize_text_field(wp_unslash($_POST['charge_default'])) : '',
        'allowed_charge_methods'    => isset($_POST['allowed_charge_methods']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['allowed_charge_methods'])) : array(),
        'hide_other_charge_methods' => ! empty($_POST['hide_other_charge_methods']),
        'max_payments'              => isset($_POST['max_payments']) ? (int) $_POST['max_payments'] : 1,
        'payments_selected'         => isset($_POST['payments_selected']) ? (int) $_POST['payments_selected'] : 0,
        'payments_credit'           => ! empty($_POST['payments_credit']),
        'add_user_information'      => ! empty($_POST['add_user_information']),
        'hide_identification_id'    => ! empty($_POST['hide_identification_id']),
        'hide_payments_field'       => ! empty($_POST['hide_payments_field']),
        'send_email_approval'       => ! empty($_POST['send_email_approval']),
        'send_email_failure'        => ! empty($_POST['send_email_failure']),
        'expiry_minutes'            => isset($_POST['expiry_minutes']) ? (int) $_POST['expiry_minutes'] : 0,
        'secure3d'                  => ! empty($_POST['secure3d']),
        'create_token'              => ! empty($_POST['create_token']),
    );

    $result = lets_payplus_signed_post('/api/woocommerce/checkout-settings', $body);

    if (is_wp_error($result)) {
        $msg = lets_payplus_reason_text($result->get_error_message());
        lets_payplus_log_error($msg, 'save_page_settings');
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array('type' => 'error', 'message' => $msg), 60);
    } else {
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array(
            'type'    => 'ok',
            'message' => __('Payment page settings saved.', 'lets-payplus'),
        ), 60);
        // Refresh the cached copy the form renders from.
        delete_transient(LETS_PAYPLUS_PAGE_SETTINGS_TRANSIENT);
    }

    lets_payplus_diag_back();
});

/** Fetch the current settings from LETS (short-lived cache so the page isn't chatty). */
function lets_payplus_fetch_page_settings()
{
    $cached = get_transient(LETS_PAYPLUS_PAGE_SETTINGS_TRANSIENT);
    if (is_array($cached)) {
        return $cached;
    }

    $result = lets_payplus_signed_get('/api/woocommerce/checkout-settings', array());

    if (is_wp_error($result) || empty($result['settings']) || ! is_array($result['settings'])) {
        return null;
    }

    set_transient(LETS_PAYPLUS_PAGE_SETTINGS_TRANSIENT, $result['settings'], 60);

    return $result['settings'];
}

/** Render the "Payment page" section on Settings → LETS. */
function lets_payplus_render_page_settings()
{
    if (! lets_payplus_connection()) {
        return;
    }

    $s = lets_payplus_fetch_page_settings();

    if (null === $s) {
        echo '<hr><h2>' . esc_html__('Payment page', 'lets-payplus') . '</h2>';
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Could not load the payment-page settings from LETS. Use “Test connection” above to see why.', 'lets-payplus')
            . '</p></div>';

        return;
    }

    $methods = isset($s['available_methods']) ? (array) $s['available_methods'] : array();
    $languages = isset($s['available_languages']) ? (array) $s['available_languages'] : array('he', 'en');
    $ceiling = isset($s['max_payments_ceiling']) ? (int) $s['max_payments_ceiling'] : 36;
    $allowed = isset($s['allowed_charge_methods']) ? (array) $s['allowed_charge_methods'] : array();
    ?>
    <hr>
    <h2><?php esc_html_e('Payment page', 'lets-payplus'); ?></h2>
    <p class="description">
        <?php esc_html_e('These options are applied to EVERY PayPlus page this store creates — normal checkout, deposits and subscriptions — so they always look the same.', 'lets-payplus'); ?>
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('lets_payplus_diagnostics'); ?>
        <input type="hidden" name="action" value="lets_payplus_save_page_settings">

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="language_code"><?php esc_html_e('Page language', 'lets-payplus'); ?></label></th>
                <td>
                    <select name="language_code" id="language_code">
                        <?php foreach ($languages as $lang) : ?>
                            <option value="<?php echo esc_attr($lang); ?>" <?php selected(isset($s['language_code']) ? $s['language_code'] : 'he', $lang); ?>>
                                <?php echo esc_html(strtoupper((string) $lang)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="charge_default"><?php esc_html_e('Default payment method', 'lets-payplus'); ?></label></th>
                <td>
                    <select name="charge_default" id="charge_default">
                        <option value=""><?php esc_html_e('— PayPlus default —', 'lets-payplus'); ?></option>
                        <?php foreach ($methods as $m) : ?>
                            <option value="<?php echo esc_attr($m); ?>" <?php selected(isset($s['charge_default']) ? $s['charge_default'] : '', $m); ?>>
                                <?php echo esc_html(lets_payplus_method_label($m)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Apple Pay and Google Pay are not switched on here — enable them on the payment page inside your PayPlus dashboard.', 'lets-payplus'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Methods to show', 'lets-payplus'); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ($methods as $m) : ?>
                            <label style="display:inline-block;margin-inline-end:14px">
                                <input type="checkbox" name="allowed_charge_methods[]" value="<?php echo esc_attr($m); ?>"
                                    <?php checked(in_array($m, $allowed, true)); ?>>
                                <?php echo esc_html(lets_payplus_method_label($m)); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('Leave all unticked to let PayPlus show its usual set.', 'lets-payplus'); ?></p>
                        <label>
                            <input type="checkbox" name="hide_other_charge_methods" value="1" <?php checked(! empty($s['hide_other_charge_methods'])); ?>>
                            <?php esc_html_e('Hide every other payment method', 'lets-payplus'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="max_payments"><?php esc_html_e('Installments', 'lets-payplus'); ?></label></th>
                <td>
                    <input type="number" min="1" max="<?php echo esc_attr($ceiling); ?>" name="max_payments" id="max_payments"
                           value="<?php echo esc_attr(isset($s['max_payments']) ? $s['max_payments'] : 1); ?>" class="small-text">
                    <span class="description"><?php esc_html_e('Maximum number of payments offered (1 = no installments).', 'lets-payplus'); ?></span>
                    <br>
                    <label>
                        <?php esc_html_e('Pre-selected:', 'lets-payplus'); ?>
                        <input type="number" min="0" name="payments_selected" class="small-text"
                               value="<?php echo esc_attr(isset($s['payments_selected']) ? (int) $s['payments_selected'] : 0); ?>">
                    </label>
                    <label style="margin-inline-start:14px">
                        <input type="checkbox" name="payments_credit" value="1" <?php checked(! empty($s['payments_credit'])); ?>>
                        <?php esc_html_e('Allow credit (ribit) transactions', 'lets-payplus'); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Fields on the page', 'lets-payplus'); ?></th>
                <td>
                    <label><input type="checkbox" name="add_user_information" value="1" <?php checked(! empty($s['add_user_information'])); ?>>
                        <?php esc_html_e('Ask for customer details', 'lets-payplus'); ?></label><br>
                    <label><input type="checkbox" name="hide_identification_id" value="1" <?php checked(! empty($s['hide_identification_id'])); ?>>
                        <?php esc_html_e('Hide the ID-number field', 'lets-payplus'); ?></label><br>
                    <label><input type="checkbox" name="hide_payments_field" value="1" <?php checked(! empty($s['hide_payments_field'])); ?>>
                        <?php esc_html_e('Hide the installments field', 'lets-payplus'); ?></label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Receipts &amp; security', 'lets-payplus'); ?></th>
                <td>
                    <label><input type="checkbox" name="send_email_approval" value="1" <?php checked(! empty($s['send_email_approval'])); ?>>
                        <?php esc_html_e('PayPlus emails the customer on success', 'lets-payplus'); ?></label><br>
                    <label><input type="checkbox" name="send_email_failure" value="1" <?php checked(! empty($s['send_email_failure'])); ?>>
                        <?php esc_html_e('PayPlus emails the customer on failure', 'lets-payplus'); ?></label><br>
                    <label><input type="checkbox" name="secure3d" value="1" <?php checked(! empty($s['secure3d'])); ?>>
                        <?php esc_html_e('Require 3-D Secure', 'lets-payplus'); ?></label><br>
                    <label>
                        <?php esc_html_e('Page expires after', 'lets-payplus'); ?>
                        <input type="number" min="0" name="expiry_minutes" class="small-text"
                               value="<?php echo esc_attr(isset($s['expiry_minutes']) ? (int) $s['expiry_minutes'] : 0); ?>">
                        <?php esc_html_e('minutes (0 = never)', 'lets-payplus'); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('One-click upsell', 'lets-payplus'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="create_token" value="1" <?php checked(! empty($s['create_token'])); ?>>
                        <strong><?php esc_html_e('Save the customer’s card for one-click post-purchase offers', 'lets-payplus'); ?></strong>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Required for the thank-you-page upsell: PayPlus returns a reusable token so the customer can add products in one click, with no card re-entry. They authorise each extra charge by clicking “Add to my order”.', 'lets-payplus'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save payment page settings', 'lets-payplus')); ?>
    </form>
    <?php
}
