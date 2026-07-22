<?php

namespace App\Domain\Invoicing\GreenInvoice;

/**
 * Green Invoice `payment[].type` codes — HOW the money arrived. Required on every
 * document type in GreenInvoiceDocumentType::REQUIRES_PAYMENT.
 *
 * LETS money always arrives through PayPlus card clearing, so CREDIT_CARD is the
 * only code the engine emits today. The rest are declared because the "all site
 * orders" scope reports plain WooCommerce orders paid by ANY gateway — a bank
 * transfer or a cash-on-delivery order must be declared as what it actually was,
 * not silently recorded as a card payment.
 */
enum GreenInvoicePaymentType: int
{
    // === CONSTANTS ===
    case UNPAID = -1;
    case CASH = 1;
    case CHEQUE = 2;
    case CREDIT_CARD = 3;
    case BANK_TRANSFER = 4;
    case PAYMENT_APP = 10;
    case OTHER = 11;

    /**
     * Map a WooCommerce payment-method id onto a payment type. WooCommerce gateway
     * ids are merchant-installable and unbounded, so anything unrecognised falls to
     * OTHER — never guessed as a card, which would misstate the document.
     */
    public static function fromWooGateway(?string $gatewayId): self
    {
        return match (strtolower(trim((string) $gatewayId))) {
            'cod' => self::CASH,
            'cheque' => self::CHEQUE,
            'bacs' => self::BANK_TRANSFER,
            'lets_payplus', 'payplus', 'stripe', 'paypal' => self::CREDIT_CARD,
            default => self::OTHER,
        };
    }
}
