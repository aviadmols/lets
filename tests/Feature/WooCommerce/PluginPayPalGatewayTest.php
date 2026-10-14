<?php

namespace Tests\Feature\WooCommerce;

use Tests\TestCase;

/**
 * The plugin's "PayPal" checkout option, pinned in the plugin source (the plugin is
 * WordPress code; these read it rather than boot WordPress).
 *
 * The option is the card option's twin — the same PayPlus page, opened on PayPal —
 * so every place that recognised "our" order by the card gateway id must now
 * recognise both, or a PayPal order is never confirmed on return (verify), or its
 * refund is mirrored instead of sent through PayPlus.
 */
final class PluginPayPalGatewayTest extends TestCase
{
    // === CONSTANTS ===
    private const DIR = 'plugins/lets-payplus-woocommerce/includes/';

    private function source(string $file): string
    {
        return (string) file_get_contents(base_path(self::DIR.$file));
    }

    public function test_the_paypal_option_is_registered_and_asks_for_paypal(): void
    {
        $gateway = $this->source('class-lets-gateway.php');

        $this->assertStringContainsString("\$gateways[] = 'LETS_PayPlus_PayPal_Gateway';", $gateway);
        $this->assertStringContainsString("define('LETS_PAYPLUS_PAYPAL_GATEWAY_ID', 'lets_payplus_paypal');", $gateway);
        $this->assertMatchesRegularExpression('/class LETS_PayPlus_PayPal_Gateway extends LETS_PayPlus_Gateway.*?const CHARGE_METHOD = \'paypal\';/s', $gateway);
        // The session request carries it — the server opens the PayPal-only page on it.
        $this->assertStringContainsString("'charge_method' => static::CHARGE_METHOD,", $gateway);
        // The iframe receipt page renders for both options.
        $this->assertStringContainsString("add_action('woocommerce_receipt_' . LETS_PAYPLUS_PAYPAL_GATEWAY_ID, \$lets_payplus_receipt);", $gateway);
    }

    public function test_a_paypal_order_is_verified_on_return_and_refunded_through_payplus(): void
    {
        $this->assertStringContainsString(
            '! lets_payplus_is_lets_gateway($order->get_payment_method())',
            $this->source('class-lets-thankyou.php'),
        );
        $this->assertStringContainsString(
            'if (lets_payplus_is_lets_gateway($order->get_payment_method())) {',
            $this->source('class-lets-refunds.php'),
        );
    }

    public function test_a_subscription_cart_is_still_locked_to_the_card_option(): void
    {
        // PayPal leaves no card to charge next month.
        $this->assertStringContainsString(
            "return isset(\$gateways['lets_payplus']) ? array('lets_payplus' => \$gateways['lets_payplus']) : array();",
            $this->source('class-lets-subscriptions.php'),
        );
    }

    public function test_the_invoicing_report_declares_a_paypal_payment_as_paypal(): void
    {
        $invoicing = $this->source('class-lets-invoicing.php');

        $this->assertStringContainsString("'payment_gateway'  => lets_payplus_invoicing_payment_gateway(\$order),", $invoicing);
        $this->assertStringContainsString("get_meta('lets_payplus_charge_method')", $invoicing);
    }
}
