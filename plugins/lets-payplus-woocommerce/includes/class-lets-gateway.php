<?php
/**
 * LETS — full PayPlus gateway for WooCommerce ("mode B", W11 P4).
 *
 * An OPTIONAL WC payment method so the merchant can run NORMAL checkout through PayPlus via
 * LETS. process_payment() asks the SaaS for a PayPlus hosted page for the order total
 * (server-signed HMAC; the api_secret never leaves the server) and redirects the shopper to
 * it. On payment, PayPlus calls the SaaS gateway callback, which marks the WC order paid via
 * the WC REST API. Coexists with the deposit/subscribe widgets + the thank-you upsell.
 *
 * Registered only when WooCommerce is active (WC_Payment_Gateway exists) and the store is
 * connected to LETS.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Register the gateway with WooCommerce (only when connected). */
add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'LETS_PayPlus_Gateway';

    return $gateways;
});

/** Define the class once WooCommerce's base class is available. */
add_action('plugins_loaded', function () {
    if (! class_exists('WC_Payment_Gateway') || class_exists('LETS_PayPlus_Gateway')) {
        return;
    }

    class LETS_PayPlus_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'lets_payplus';
            $this->method_title = __('PayPlus (LETS)', 'lets-payplus');
            $this->method_description = __('Accept payments through PayPlus via your LETS connection.', 'lets-payplus');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', __('Pay with PayPlus', 'lets-payplus'));
            $this->description = $this->get_option('description', '');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'lets-payplus'),
                    'type' => 'checkbox',
                    'label' => __('Enable PayPlus (LETS) checkout', 'lets-payplus'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'lets-payplus'),
                    'type' => 'text',
                    'default' => __('Pay with PayPlus', 'lets-payplus'),
                ),
                'description' => array(
                    'title' => __('Description', 'lets-payplus'),
                    'type' => 'textarea',
                    'default' => __('You will be redirected to PayPlus to complete your payment securely.', 'lets-payplus'),
                ),
            );
        }

        /** Only available when the store is connected to LETS. */
        public function is_available()
        {
            return parent::is_available() && function_exists('lets_payplus_connection') && lets_payplus_connection() !== null;
        }

        /**
         * Ask the SaaS for a PayPlus page for the order total, then redirect there. The
         * SaaS gateway callback marks the order paid on completion; here we leave the order
         * `pending` (awaiting payment) and hand WooCommerce the redirect.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (! $order) {
                wc_add_notice(__('We could not start the payment. Please try again.', 'lets-payplus'), 'error');

                return array('result' => 'failure');
            }

            $body = array(
                'order_id' => (string) $order->get_id(),
                'amount' => (float) $order->get_total(),
                'currency' => (string) $order->get_currency(),
                'product_name' => sprintf(__('Order %s', 'lets-payplus'), $order->get_order_number()),
                'email' => (string) $order->get_billing_email(),
                'return_url' => $this->get_return_url($order),
                'cancel_url' => $order->get_cancel_order_url_raw(),
            );

            $result = lets_payplus_signed_post('/api/woocommerce/gateway/session', $body);

            if (is_wp_error($result) || empty($result['redirect_url'])) {
                // Turn the failure into a CLEAR, reason-specific message for the shopper —
                // and a detailed line in the WooCommerce log for the merchant — instead of
                // silently staying on checkout with no payment page.
                $code   = is_wp_error($result) ? $result->get_error_code() : 'no_redirect_url';
                $status = is_wp_error($result) ? (int) ($result->get_error_data()['status'] ?? 0) : 0;
                $detail = is_wp_error($result) ? $result->get_error_message() : 'no_redirect_url';

                if ('lets_not_connected' === $code || 'lets_bad_origin' === $code) {
                    $reason = __('This store is not connected to LETS yet. Please contact the store — the LETS connection token needs to be set in Settings → LETS.', 'lets-payplus');
                } elseif ('payplus_not_connected' === $detail) {
                    $reason = __('The store’s PayPlus account is not connected. Please contact the store to enter its PayPlus API key and secret.', 'lets-payplus');
                } elseif ('payplus_no_payment_page' === $detail) {
                    $reason = __('The store’s PayPlus payment page is not set up yet. Please contact the store to finish the PayPlus connection (choose a Payment Page).', 'lets-payplus');
                } elseif (401 === $status || 403 === $status) {
                    $reason = __('LETS could not authenticate this store (the connection token may be out of date). Please contact the store to re-connect the plugin.', 'lets-payplus');
                } elseif ($status >= 500) {
                    $reason = __('The payment service is temporarily unavailable. Please try again in a few minutes.', 'lets-payplus');
                } else {
                    $reason = __('Could not open the PayPlus payment page. Please try again, or contact the store if it keeps happening.', 'lets-payplus');
                }

                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error(
                        sprintf(
                            'LETS gateway: could not start payment for order %d — code=%s status=%d detail=%s',
                            (int) $order->get_id(),
                            (string) $code,
                            $status,
                            (string) $detail
                        ),
                        array('source' => 'lets-payplus')
                    );
                }

                // Surface the error on BOTH classic AND block checkout. A failure RETURN is
                // silently ignored by the block (Store API) checkout — only a thrown
                // exception is rendered there — so we add the notice (classic) AND throw.
                wc_add_notice($reason, 'error');
                throw new \Exception(esc_html($reason));
            }

            // Mark awaiting payment; reduce stock holds per WC defaults.
            $order->update_status('pending', __('Awaiting PayPlus payment via LETS.', 'lets-payplus'));

            return array(
                'result' => 'success',
                'redirect' => (string) $result['redirect_url'],
            );
        }
    }
}, 11);
