<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\DocumentDecision;
use App\Domain\Billing\Contracts\DocumentPolicy;
use App\Domain\Billing\Contracts\DocumentPolicyInput;

/**
 * The default document policy. Maps a (charge_context, plan_kind, is_final)
 * tuple to a PayPlus "books" document type, reading the TYPE NAMES from
 * config('payplus.document_types.*') — NEVER from the orchestrator. This is the
 * decoupling the contract demands: the reference engine's issueTaxInvoiceForPlan
 * read config('payplus.document_types.tax_invoice') inline; here that decision
 * lives in one swappable policy.
 *
 * Per-shop merchant overrides (e.g. "receipt only", "tax invoice on completion")
 * arrive in a later phase via DocumentPolicyInput::$merchantSettings — this
 * default honours an optional 'document_mode' hint already.
 */
final class DefaultDocumentPolicy implements DocumentPolicy
{
    // === CONSTANTS ===
    public const CONTEXT_DEPOSIT = 'deposit';
    public const CONTEXT_INSTALLMENT = 'installment';
    public const CONTEXT_FINAL_INSTALLMENT = 'final_installment';
    public const CONTEXT_RECURRING = 'recurring';
    public const CONTEXT_UPSELL = 'upsell';
    public const CONTEXT_REFUND = 'refund';
    public const CONTEXT_CANCELLATION = 'cancellation';
    /**
     * A plain paid store order — no LETS plan involved. Reported by the storefront
     * when the merchant runs invoicing in `all_orders` scope. It is a complete sale
     * in its own right, so it earns the full tax document.
     */
    public const CONTEXT_PLATFORM_ORDER = 'platform_order';

    public function decide(DocumentPolicyInput $input): DocumentDecision
    {
        // Normalise the effective context: a final installment payment is its own
        // policy context even though it arrives as charge_context = installment.
        $context = $input->chargeContext;
        if ($context === self::CONTEXT_INSTALLMENT && $input->isFinalPayment) {
            $context = self::CONTEXT_FINAL_INSTALLMENT;
        }

        $types = (array) config('payplus.document_types', []);
        $taxInvoice = $types['tax_invoice'] ?? null;
        $receipt = $types['receipt'] ?? null;
        $refund = $types['refund'] ?? null;

        // Merchant override hint (full per-shop settings land in a later phase).
        $mode = $input->merchantSettings['document_mode'] ?? 'default';
        if ($mode === 'none') {
            return DocumentDecision::none();
        }

        return match ($context) {
            // Deposit: a receipt now; the tax invoice is issued on completion.
            self::CONTEXT_DEPOSIT => new DocumentDecision(
                documentType: $receipt,
                shouldIssueNow: $receipt !== null,
            ),

            // Mid-stream installment: receipt per payment.
            self::CONTEXT_INSTALLMENT => new DocumentDecision(
                documentType: $receipt,
                shouldIssueNow: $receipt !== null,
            ),

            // Final installment: the tax invoice (links the whole plan), issued now.
            self::CONTEXT_FINAL_INSTALLMENT => new DocumentDecision(
                documentType: $taxInvoice,
                shouldIssueNow: $taxInvoice !== null,
                shouldLinkToPreviousDocument: true,
            ),

            // Recurring cycle, upsell, plain store order: full tax invoice per charge
            // (each is a complete sale in its own right).
            self::CONTEXT_RECURRING,
            self::CONTEXT_PLATFORM_ORDER,
            self::CONTEXT_UPSELL => new DocumentDecision(
                documentType: $taxInvoice,
                shouldIssueNow: $taxInvoice !== null,
            ),

            // Refund / cancellation: a credit document linked to the original.
            self::CONTEXT_REFUND,
            self::CONTEXT_CANCELLATION => new DocumentDecision(
                documentType: $refund,
                shouldIssueNow: $refund !== null,
                shouldLinkToPreviousDocument: true,
            ),

            default => DocumentDecision::none(),
        };
    }
}
