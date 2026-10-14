<?php

namespace App\Domain\Invoicing\GreenInvoice;

/**
 * Green Invoice `payment[].type` codes — HOW the money arrived. Required on every
 * document type in GreenInvoiceDocumentType::REQUIRES_PAYMENT.
 *
 * LETS plan money always arrives through PayPlus card clearing, so CREDIT_CARD is
 * the engine's own code. The rest exist because the "all site orders" scope reports
 * plain WooCommerce orders paid by ANY gateway — a bank transfer, cash on delivery
 * or PayPal must be declared as what it actually was, not recorded as a card.
 */
enum GreenInvoicePaymentType: int
{
    // === CONSTANTS ===
    case UNPAID = -1;
    case CASH = 1;
    case CHEQUE = 2;
    case CREDIT_CARD = 3;
    case BANK_TRANSFER = 4;
    case PAYPAL = 5;
    case PAYMENT_APP = 10;
    case OTHER = 11;

    /** WooCommerce gateway ids that are card clearing. */
    private const CARD_GATEWAYS = ['lets_payplus', 'payplus', 'payplus-payment-gateway', 'stripe'];

    /**
     * Gateway-id fragments that mean the money went through PayPal: core PayPal
     * Standard (`paypal`), the official WooCommerce PayPal Payments plugin
     * (`ppcp-gateway`, and its `ppcp-*` card / Apple Pay / Google Pay / Fastlane
     * buttons — all settled into the merchant's PayPal account), the legacy
     * PayPal Checkout (`ppec_paypal`) and third-party PayPal plugins.
     */
    private const PAYPAL_PREFIXES = ['ppcp'];

    private const PAYPAL_FRAGMENT = 'paypal';

    /**
     * Map a WooCommerce payment-method id onto a payment type. WooCommerce gateway
     * ids are merchant-installable and unbounded, so anything unrecognised falls to
     * OTHER — never guessed as a card, which would misstate the document.
     */
    public static function fromWooGateway(?string $gatewayId): self
    {
        $id = strtolower(trim((string) $gatewayId));

        return match (true) {
            $id === 'cod' => self::CASH,
            $id === 'cheque' => self::CHEQUE,
            $id === 'bacs' => self::BANK_TRANSFER,
            in_array($id, self::CARD_GATEWAYS, true) => self::CREDIT_CARD,
            self::isPayPal($id) => self::PAYPAL,
            default => self::OTHER,
        };
    }

    private static function isPayPal(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        foreach (self::PAYPAL_PREFIXES as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return str_contains($id, self::PAYPAL_FRAGMENT);
    }
}
