<?php

namespace Tests\Unit\Invoicing;

use App\Domain\Invoicing\GreenInvoice\GreenInvoicePaymentType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How a WooCommerce gateway id is declared on a Green Invoice document. A PayPal
 * payment is PayPal (type 5) whichever plugin took it — ours, PayPlus's own, the
 * official WooCommerce PayPal Payments, or core PayPal Standard — and a payment
 * nobody recognises is OTHER, never guessed as a card.
 */
final class GreenInvoicePaymentTypeTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: GreenInvoicePaymentType}> */
    public static function gateways(): array
    {
        return [
            'our card option' => ['lets_payplus', GreenInvoicePaymentType::CREDIT_CARD],
            'our PayPal option' => ['lets_payplus_paypal', GreenInvoicePaymentType::PAYPAL],
            'PayPlus plugin card' => ['payplus-payment-gateway', GreenInvoicePaymentType::CREDIT_CARD],
            'PayPlus plugin PayPal' => ['payplus-payment-gateway-paypal', GreenInvoicePaymentType::PAYPAL],
            'WooCommerce PayPal Payments' => ['ppcp-gateway', GreenInvoicePaymentType::PAYPAL],
            'PayPal Payments card fields' => ['ppcp-credit-card-gateway', GreenInvoicePaymentType::PAYPAL],
            'core PayPal Standard' => ['paypal', GreenInvoicePaymentType::PAYPAL],
            'legacy PayPal Checkout' => ['ppec_paypal', GreenInvoicePaymentType::PAYPAL],
            'upper case' => ['PayPal', GreenInvoicePaymentType::PAYPAL],
            'bank transfer' => ['bacs', GreenInvoicePaymentType::BANK_TRANSFER],
            'cash on delivery' => ['cod', GreenInvoicePaymentType::CASH],
            'cheque' => ['cheque', GreenInvoicePaymentType::CHEQUE],
            'stripe' => ['stripe', GreenInvoicePaymentType::CREDIT_CARD],
            'unknown' => ['some_gateway', GreenInvoicePaymentType::OTHER],
            'empty' => ['', GreenInvoicePaymentType::OTHER],
            'null' => [null, GreenInvoicePaymentType::OTHER],
        ];
    }

    #[DataProvider('gateways')]
    public function test_a_gateway_is_declared_as_what_it_is(?string $gateway, GreenInvoicePaymentType $expected): void
    {
        $this->assertSame($expected, GreenInvoicePaymentType::fromWooGateway($gateway));
    }

    public function test_paypal_is_green_invoice_type_5(): void
    {
        $this->assertSame(5, GreenInvoicePaymentType::PAYPAL->value);
    }
}
