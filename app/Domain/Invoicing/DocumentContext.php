<?php

namespace App\Domain\Invoicing;

use App\Domain\Billing\DefaultDocumentPolicy;

/**
 * The money event a document is issued FOR. The seven billing contexts mirror
 * App\Domain\Billing\DefaultDocumentPolicy::CONTEXT_* VERBATIM (the policy still
 * owns the "should we issue now, and is it linked" decision — this enum only
 * gives the invoicing module a typed, serialisable handle on the same value).
 *
 * PLATFORM_ORDER is the context the invoicing module ADDED to that vocabulary,
 * and DefaultDocumentPolicy::CONTEXT_PLATFORM_ORDER was added alongside it: a
 * plain store order that never touched a LETS plan, paid by any method, reported
 * by the storefront when the merchant runs the module in `all_orders` scope. The
 * policy answers for it like any other context (it is a complete sale), so it is
 * declared here from that same constant rather than routed around the policy.
 */
enum DocumentContext: string
{
    // === CONSTANTS === (values mirror DefaultDocumentPolicy::CONTEXT_*)
    case DEPOSIT = DefaultDocumentPolicy::CONTEXT_DEPOSIT;
    case INSTALLMENT = DefaultDocumentPolicy::CONTEXT_INSTALLMENT;
    case FINAL_INSTALLMENT = DefaultDocumentPolicy::CONTEXT_FINAL_INSTALLMENT;
    case RECURRING = DefaultDocumentPolicy::CONTEXT_RECURRING;
    case UPSELL = DefaultDocumentPolicy::CONTEXT_UPSELL;
    case REFUND = DefaultDocumentPolicy::CONTEXT_REFUND;
    case CANCELLATION = DefaultDocumentPolicy::CONTEXT_CANCELLATION;

    /** A plain paid store order — no LETS plan involved (`all_orders` scope). */
    case PLATFORM_ORDER = DefaultDocumentPolicy::CONTEXT_PLATFORM_ORDER;

    /**
     * Does this context REDUCE the merchant's income (a credit note) rather than
     * record a sale? Drives the linked-document requirement in the provider.
     */
    public function isCredit(): bool
    {
        return $this === self::REFUND || $this === self::CANCELLATION;
    }

    /** Every context, as plain values — the settings screen renders one row each. */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
