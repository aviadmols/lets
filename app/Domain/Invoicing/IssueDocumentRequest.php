<?php

namespace App\Domain\Invoicing;

use App\Models\Shop;

/**
 * Everything a provider needs to issue ONE document, in provider-neutral terms.
 * Built by DocumentIssuer from the money event; consumed by an InvoiceProvider,
 * which maps it onto its own wire shape.
 *
 * The amount is NOT a free field: `amount` must equal the sum of the lines. It is
 * carried separately only so the issuer can record it on the issued_documents row
 * and assert the invariant before any HTTP call — a document whose total drifts
 * from the money that actually moved is an accounting error, not a display bug.
 */
final class IssueDocumentRequest
{
    public function __construct(
        public readonly Shop $shop,
        public readonly DocumentContext $context,
        public readonly DocumentCustomer $customer,
        /** @var list<DocumentLine> */
        public readonly array $lines,
        public readonly float $amount,
        public readonly string $currency,
        /** Whether money actually changed hands (drives the provider's payment[]). */
        public readonly bool $isPaid = true,
        /** The provider's id of the document this one credits/links to, when any. */
        public readonly ?string $linkedDocumentId = null,
        /** Free-text shown on the document (order number, plan reference). */
        public readonly ?string $remarks = null,
        /** Ask the provider to email the document to the customer. */
        public readonly bool $sendEmail = false,
        /**
         * The storefront gateway id the money arrived through (e.g. a WooCommerce
         * `payment_method` such as `bacs` or `cod`), when the platform reported one.
         * NULL means LETS's own PayPlus card clearing — the only way plan money ever
         * moves. The provider maps this to its payment-means code; it is never
         * guessed, because declaring a bank transfer as a card payment misstates the
         * document.
         */
        public readonly ?string $paymentGateway = null,
        /** Last 4 digits of the card, when the platform reported them. */
        public readonly ?string $cardLast4 = null,
    ) {}

    /** The sum of the lines — the number the provider will actually total to. */
    public function lineTotal(): float
    {
        return round(array_sum(array_map(
            static fn (DocumentLine $line): float => $line->total(),
            $this->lines,
        )), 2);
    }

    /**
     * Do the lines add up to the money that moved (to the cent)? DocumentIssuer
     * refuses to issue when this is false — better a recorded failure the merchant
     * can see than a tax document for the wrong amount.
     */
    public function totalsMatch(): bool
    {
        return abs($this->lineTotal() - round($this->amount, 2)) < 0.01;
    }
}
