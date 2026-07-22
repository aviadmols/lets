<?php

namespace App\Domain\Invoicing\GreenInvoice;

/**
 * Green Invoice ("Morning") document type codes, as documented at
 * https://developers.morning.co — the numeric `type` field of POST /documents.
 *
 * Israeli accounting semantics, because the choice is not cosmetic:
 *   300 חשבונית עסקה      — a proforma/transaction invoice. NOT a tax document;
 *                            no VAT is reported. Money may not have moved yet.
 *   305 חשבונית מס         — a tax invoice. Reports VAT. An Osek Patur (exempt
 *                            dealer) may NOT issue this — hence the per-context
 *                            merchant override.
 *   320 חשבונית מס/קבלה    — tax invoice + receipt in one. The normal document
 *                            for "a complete sale, paid now".
 *   330 חשבונית זיכוי      — a credit note. Reduces a previously reported sale;
 *                            must link to the original document.
 *   400 קבלה               — a receipt. Records money received WITHOUT declaring
 *                            a completed sale — the right document for a deposit
 *                            or a mid-stream installment.
 *   405 קבלת תרומה         — a donation receipt (non-profits).
 *
 * REQUIRES_PAYMENT: types 320/400/405 are rejected by the API without a
 * non-empty `payment[]` array — they assert money actually changed hands.
 */
enum GreenInvoiceDocumentType: int
{
    // === CONSTANTS ===
    case TRANSACTION_INVOICE = 300;
    case TAX_INVOICE = 305;
    case TAX_INVOICE_RECEIPT = 320;
    case CREDIT_NOTE = 330;
    case RECEIPT = 400;
    case DONATION_RECEIPT = 405;

    /** Types the API rejects unless a non-empty payment[] array is supplied. */
    public const REQUIRES_PAYMENT = [320, 400, 405];

    public function requiresPayment(): bool
    {
        return in_array($this->value, self::REQUIRES_PAYMENT, true);
    }

    /** A credit note must reference the document it credits. */
    public function requiresLinkedDocument(): bool
    {
        return $this === self::CREDIT_NOTE;
    }

    /**
     * The translation key for this type's merchant-facing label. Kept here (not as
     * a hardcoded Hebrew/English string) so the settings screen stays i18n-clean.
     */
    public function labelKey(): string
    {
        return 'settings.invoicing.doc_type.'.$this->value;
    }

    /** A safe case for an unknown/legacy stored value, or null. */
    public static function tryFromMixed(mixed $value): ?self
    {
        return is_numeric($value) ? self::tryFrom((int) $value) : null;
    }
}
