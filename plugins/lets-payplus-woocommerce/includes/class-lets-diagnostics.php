<?php
/**
 * LETS — merchant diagnostics on Settings → LETS.
 *
 * Three tools the merchant can run themselves, so a broken checkout is never a mystery:
 *
 *   1. "Test connection"   → POST /api/woocommerce/diagnostics. Renders a ✅/❌ checklist:
 *                            LETS auth · WooCommerce API · PayPlus keys · terminal ·
 *                            PAYMENT PAGE (the piece whose absence silently kills checkout).
 *   2. "Test payment page" → POST /api/woocommerce/diagnostics/payment-page. Asks PayPlus for
 *                            a REAL hosted card page and PRINTS THE URL (open it to see the
 *                            actual credit-card form). It is a probe page — never charged.
 *   3. "Recent errors"     → every LETS failure (checkout + diagnostics) is appended to a
 *                            capped, merchant-visible log right on this screen.
 *
 * All calls are HMAC-signed SERVER-SIDE via lets_payplus_signed_post() — the api_secret never
 * reaches the browser. Every handler is nonce-checked and manage_options-gated.
 */

if (! defined('ABSPATH')) {
    exit;
}

// === CONSTANTS ===
define('LETS_PAYPLUS_ERROR_LOG_OPT', 'lets_payplus_error_log');
define('LETS_PAYPLUS_ERROR_LOG_MAX', 50);
define('LETS_PAYPLUS_DIAG_TRANSIENT', 'lets_payplus_diag');
define('LETS_PAYPLUS_NOTIFY_OPT', 'lets_payplus_notify_on_error'); // '1' (default) or '0'

/**
 * Append a timestamped row to the capped, merchant-visible ACTIVITY log. $level is one of
 * info|success|error (legacy rows without a level render as error). Called from the gateway,
 * the diagnostics handlers, verify-on-return, and the SaaS→plugin notify route.
 */
function lets_payplus_log_event($message, $context = '', $level = 'error')
{
    $log = get_option(LETS_PAYPLUS_ERROR_LOG_OPT, array());
    if (! is_array($log)) {
        $log = array();
    }

    array_unshift($log, array(
        'time'    => current_time('mysql'),
        'level'   => in_array($level, array('info', 'success', 'error'), true) ? $level : 'error',
        'context' => (string) $context,
        'message' => (string) $message,
    ));

    update_option(LETS_PAYPLUS_ERROR_LOG_OPT, array_slice($log, 0, LETS_PAYPLUS_ERROR_LOG_MAX), false);
}

/** Back-compat wrapper: an error-level event (existing call sites keep working). */
function lets_payplus_log_error($message, $context = '')
{
    lets_payplus_log_event($message, $context, 'error');
}

/** Whether the merchant wants an admin email on payment/iframe errors (default: yes). */
function lets_payplus_notify_enabled()
{
    return get_option(LETS_PAYPLUS_NOTIFY_OPT, '1') !== '0';
}

/**
 * Email the WordPress site admin about a payment/iframe error (best-effort, gated by the
 * "notify on error" setting). Sent with the site's own mail (wp_mail) to admin_email.
 */
function lets_payplus_notify_admin($subject, $message)
{
    if (! lets_payplus_notify_enabled()) {
        return;
    }
    $to = (string) get_option('admin_email');
    if ($to === '') {
        return;
    }
    $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    wp_mail(
        $to,
        sprintf('[%s] %s', $site, (string) $subject),
        (string) $message . "\n\n" . sprintf(__('Site: %s', 'lets-payplus'), home_url())
    );
}

/** Turn a machine reason from the SaaS into text a merchant can act on. */
function lets_payplus_reason_text($reason)
{
    switch ($reason) {
        case 'payplus_not_connected':
            return __('PayPlus is not connected. Enter the PayPlus API key and secret in your LETS dashboard (Settings → PayPlus Connection).', 'lets-payplus');
        case 'payplus_no_terminal':
            return __('No PayPlus terminal is selected in your LETS dashboard.', 'lets-payplus');
        case 'payplus_no_payment_page':
            return __('No PayPlus PAYMENT PAGE is selected. PayPlus cannot create a credit-card page without one — choose a Payment Page in your LETS dashboard. If the list is empty, create a payment page in your PayPlus dashboard first.', 'lets-payplus');
        default:
            return (string) $reason;
    }
}

// === Handlers ===

add_action('admin_post_lets_payplus_test_connection', function () {
    lets_payplus_diag_guard();

    $result = lets_payplus_signed_post('/api/woocommerce/diagnostics', array());

    if (is_wp_error($result)) {
        $msg = lets_payplus_reason_text($result->get_error_message());
        lets_payplus_log_error($msg, 'test_connection');
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array('type' => 'error', 'message' => $msg), 60);
    } else {
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array('type' => 'report', 'report' => $result), 60);
    }

    lets_payplus_diag_back();
});

add_action('admin_post_lets_payplus_test_payment_page', function () {
    lets_payplus_diag_guard();

    $result = lets_payplus_signed_post('/api/woocommerce/diagnostics/payment-page', array());

    if (is_wp_error($result)) {
        // The SaaS puts its machine reason (or PayPlus's own words) in the message.
        $msg = lets_payplus_reason_text($result->get_error_message());
        lets_payplus_log_error($msg, 'test_payment_page');
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array('type' => 'page_error', 'message' => $msg), 60);
    } else {
        $url = isset($result['payment_page_url']) ? (string) $result['payment_page_url'] : '';
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array('type' => 'page_ok', 'url' => $url), 60);
    }

    lets_payplus_diag_back();
});

add_action('admin_post_lets_payplus_clear_log', function () {
    lets_payplus_diag_guard();
    delete_option(LETS_PAYPLUS_ERROR_LOG_OPT);
    lets_payplus_diag_back();
});

function lets_payplus_diag_guard()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to do this.', 'lets-payplus'), 403);
    }

    check_admin_referer('lets_payplus_diagnostics');
}

function lets_payplus_diag_back()
{
    wp_safe_redirect(admin_url('options-general.php?page=lets-payplus'));
    exit;
}

// === Rendering (called from the Settings → LETS page) ===

function lets_payplus_render_diagnostics()
{
    // Nothing to test until the store is linked to LETS.
    if (! lets_payplus_connection()) {
        return;
    }

    $diag = get_transient(LETS_PAYPLUS_DIAG_TRANSIENT);
    delete_transient(LETS_PAYPLUS_DIAG_TRANSIENT);
    ?>
    <hr>
    <h2><?php esc_html_e('Diagnostics', 'lets-payplus'); ?></h2>
    <p class="description">
        <?php esc_html_e('Check the connection, and prove that PayPlus can actually create a credit-card page for this store.', 'lets-payplus'); ?>
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-inline-end:8px">
        <?php wp_nonce_field('lets_payplus_diagnostics'); ?>
        <input type="hidden" name="action" value="lets_payplus_test_connection">
        <?php submit_button(__('Test connection', 'lets-payplus'), 'secondary', 'submit', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
        <?php wp_nonce_field('lets_payplus_diagnostics'); ?>
        <input type="hidden" name="action" value="lets_payplus_test_payment_page">
        <?php submit_button(__('Test payment page', 'lets-payplus'), 'secondary', 'submit', false); ?>
    </form>

    <?php
    if (is_array($diag)) {
        lets_payplus_render_diag_result($diag);
    }

    lets_payplus_render_error_log();
}

function lets_payplus_render_diag_result($diag)
{
    $type = isset($diag['type']) ? $diag['type'] : '';

    if ('page_ok' === $type && ! empty($diag['url'])) {
        echo '<div class="notice notice-success"><p><strong>'
            . esc_html__('PayPlus created a payment page — checkout can reach the card form.', 'lets-payplus')
            . '</strong><br><a href="' . esc_url($diag['url']) . '" target="_blank" rel="noopener">'
            . esc_html($diag['url']) . '</a><br><em>'
            . esc_html__('Open it to see the real credit-card page. This is a test page and is never charged.', 'lets-payplus')
            . '</em></p></div>';

        return;
    }

    if ('page_error' === $type || 'error' === $type) {
        echo '<div class="notice notice-error"><p>' . esc_html($diag['message']) . '</p></div>';

        return;
    }

    if ('ok' === $type) {
        echo '<div class="notice notice-success"><p>' . esc_html($diag['message']) . '</p></div>';

        return;
    }

    if ('report' !== $type || empty($diag['report']) || ! is_array($diag['report'])) {
        return;
    }

    $report = $diag['report'];
    $wc = isset($report['woocommerce']) && is_array($report['woocommerce']) ? $report['woocommerce'] : array();
    $pp = isset($report['payplus']) && is_array($report['payplus']) ? $report['payplus'] : array();

    $wc_note = (isset($wc['lines']) && is_array($wc['lines'])) ? implode(' ', $wc['lines']) : '';

    echo '<table class="widefat striped" style="max-width:760px;margin-top:12px"><tbody>';
    // Reaching the SaaS at all proves the HMAC auth — that is what this row means.
    lets_payplus_diag_row(__('LETS connection (authentication)', 'lets-payplus'), true);
    lets_payplus_diag_row(__('WooCommerce API', 'lets-payplus'), ! empty($wc['ok']), $wc_note);
    lets_payplus_diag_row(__('PayPlus API key + secret', 'lets-payplus'), ! empty($pp['has_api_key']) && ! empty($pp['has_secret_key']));
    lets_payplus_diag_row(__('PayPlus terminal', 'lets-payplus'), ! empty($pp['has_terminal_uid']));
    lets_payplus_diag_row(
        __('PayPlus payment page', 'lets-payplus'),
        ! empty($pp['has_payment_page_uid']),
        empty($pp['has_payment_page_uid']) ? lets_payplus_reason_text('payplus_no_payment_page') : ''
    );

    // W17 — the three facts that answer "am I on production, is THIS the page I designed, and will
    // checkout actually capture money?". A generic page or missing dashboard txns usually means the
    // environment is sandbox OR the charge mode is 0 (verify-only).
    $env = isset($pp['environment']) ? (string) $pp['environment'] : '';
    $masked_uid = isset($pp['payment_page_uid_masked']) ? (string) $pp['payment_page_uid_masked'] : '';
    $charge_method = isset($pp['charge_method']) ? (int) $pp['charge_method'] : null;
    if ('' !== $env || '' !== $masked_uid || null !== $charge_method) {
        $detail = array();
        if ('' !== $env) {
            /* translators: %s: PayPlus environment (PRODUCTION / SANDBOX). */
            $detail[] = sprintf(__('Environment: %s', 'lets-payplus'), strtoupper($env));
        }
        if ('' !== $masked_uid) {
            /* translators: %s: masked payment-page id. */
            $detail[] = sprintf(__('Payment page: %s (confirm this is the page you designed)', 'lets-payplus'), $masked_uid);
        }
        if (null !== $charge_method) {
            $detail[] = (0 !== $charge_method)
                /* translators: %d: PayPlus charge_method code. */
                ? sprintf(__('Charge mode: %d (captures money)', 'lets-payplus'), $charge_method)
                : __('Charge mode: 0 — WARNING: verify-only, NO money is captured', 'lets-payplus');
        }
        $row_ok = ('production' === $env) && (0 !== $charge_method);
        lets_payplus_diag_row(__('Environment & charge mode', 'lets-payplus'), $row_ok, implode(' · ', $detail));
    }

    echo '</tbody></table>';

    if (empty($pp['ready'])) {
        $reason = isset($pp['reason']) ? $pp['reason'] : '';
        echo '<div class="notice notice-error" style="margin-top:12px"><p>'
            . esc_html(lets_payplus_reason_text($reason)) . '</p></div>';
    } else {
        echo '<div class="notice notice-success" style="margin-top:12px"><p>'
            . esc_html__('PayPlus is fully configured. Use “Test payment page” to prove it end-to-end.', 'lets-payplus')
            . '</p></div>';
    }
}

function lets_payplus_diag_row($label, $ok, $note = '')
{
    echo '<tr><td style="width:34px;font-size:16px">' . ($ok ? '✅' : '❌') . '</td><td><strong>'
        . esc_html($label) . '</strong>';

    if ('' !== (string) $note) {
        echo '<br><span class="description">' . esc_html($note) . '</span>';
    }

    echo '</td></tr>';
}

function lets_payplus_render_error_log()
{
    $log = get_option(LETS_PAYPLUS_ERROR_LOG_OPT, array());
    if (! is_array($log) || array() === $log) {
        return;
    }
    ?>
    <h2 style="margin-top:24px"><?php esc_html_e('Activity log', 'lets-payplus'); ?></h2>
    <table class="widefat striped" style="max-width:760px">
        <thead>
            <tr>
                <th style="width:160px"><?php esc_html_e('When', 'lets-payplus'); ?></th>
                <th style="width:70px"></th>
                <th style="width:130px"><?php esc_html_e('Where', 'lets-payplus'); ?></th>
                <th><?php esc_html_e('Message', 'lets-payplus'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($log as $row) : ?>
            <tr>
                <td><?php echo esc_html(isset($row['time']) ? $row['time'] : ''); ?></td>
                <td style="text-align:center"><?php $lv = isset($row['level']) ? $row['level'] : 'error'; echo 'success' === $lv ? '✅' : ('info' === $lv ? 'ℹ️' : '⛔'); ?></td>
                <td><code><?php echo esc_html(isset($row['context']) ? $row['context'] : ''); ?></code></td>
                <td><?php echo esc_html(isset($row['message']) ? $row['message'] : ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
        <?php wp_nonce_field('lets_payplus_diagnostics'); ?>
        <input type="hidden" name="action" value="lets_payplus_clear_log">
        <?php submit_button(__('Clear log', 'lets-payplus'), 'small', 'submit', false); ?>
    </form>
    <?php
}
