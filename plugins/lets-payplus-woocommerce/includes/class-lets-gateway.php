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
                'display_mode' => array(
                    'title' => __('Display', 'lets-payplus'),
                    'type' => 'select',
                    'default' => 'redirect',
                    'options' => array(
                        'redirect' => __('Redirect to PayPlus (recommended)', 'lets-payplus'),
                        'iframe' => __('Embed the PayPlus page on my site (iframe)', 'lets-payplus'),
                    ),
                    'description' => __('“Redirect” sends the shopper to PayPlus’s secure page. “Embed” keeps them on your site by showing that same page in an iframe. PayPlus has no iframe API — this is a display choice; the card form is still served by PayPlus.', 'lets-payplus'),
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
                // Prefill the PayPlus card page with the shopper's details from the order.
                'first_name' => (string) $order->get_billing_first_name(),
                'last_name' => (string) $order->get_billing_last_name(),
                'email' => (string) $order->get_billing_email(),
                'phone' => (string) $order->get_billing_phone(),
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

                // Mirror it into the merchant-visible log on Settings → LETS, so a failed
                // checkout is diagnosable without digging through WooCommerce → Status → Logs.
                if (function_exists('lets_payplus_log_error')) {
                    lets_payplus_log_error($reason, 'checkout');
                }

                // THROW — do not also wc_add_notice(). WooCommerce catches an exception from
                // process_payment() and adds the notice itself, so doing both printed the
                // message TWICE on classic checkout. Throwing is also the only form the block
                // (Store API) checkout renders — a ['result' => 'failure'] return is silent.
                throw new \Exception(esc_html($reason));
            }

            // Mark awaiting payment; reduce stock holds per WC defaults.
            $order->update_status('pending', __('Awaiting PayPlus payment via LETS.', 'lets-payplus'));

            // Store the page-request id so we can VERIFY-ON-RETURN on the thank-you page — the
            // reliable way to mark the order paid when PayPlus doesn't push the callback.
            if (! empty($result['page_request_uid'])) {
                $order->update_meta_data('_lets_payplus_page_request_uid', (string) $result['page_request_uid']);
                $order->save();
            }

            $payplusUrl = (string) $result['redirect_url'];

            // IFRAME mode: keep the shopper on our site. Park the PayPlus URL on the order and
            // send them to WooCommerce's order-pay page, whose receipt hook (below) embeds it.
            // PayPlus completion still fires refURL_callback → the SaaS marks the order paid, and
            // refURL_success returns the shopper to the WooCommerce "order received" page.
            if ($this->get_option('display_mode', 'redirect') === 'iframe') {
                $order->update_meta_data('_lets_payplus_page_url', $payplusUrl);
                $order->save();

                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true),
                );
            }

            // REDIRECT mode (default): straight to the PayPlus hosted page.
            return array(
                'result' => 'success',
                'redirect' => $payplusUrl,
            );
        }
    }

    // Receipt (order-pay) page for iframe mode: render the PayPlus page embedded. Guarded so
    // it only shows for THIS order's owner and only in iframe mode.
    add_action('woocommerce_receipt_lets_payplus', function ($order_id) {
        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $url = (string) $order->get_meta('_lets_payplus_page_url');
        if ($url === '') {
            return;
        }

        echo '<div class="lets-payplus-iframe-wrap" style="position:relative;width:100%;min-height:720px">';
        echo '<iframe src="' . esc_url($url) . '" title="' . esc_attr__('Secure PayPlus payment', 'lets-payplus') . '"'
            . ' style="width:100%;min-height:720px;border:0" allow="payment"></iframe>';
        echo '<p style="margin-top:10px"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html__('Trouble seeing the payment form? Open it in a new tab.', 'lets-payplus') . '</a></p>';
        echo '</div>';
    });
}, 11);
