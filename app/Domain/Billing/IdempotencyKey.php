<?php

namespace App\Domain\Billing;

/**
 * Deterministic idempotency keys (see ARCHITECTURE.md). The system must never
 * send a second PayPlus charge if a `succeeded` ledger event already exists for
 * the same key. Protects against double-clicks, webhook/worker retries,
 * scheduler overlap, and manual admin retries.
 */
final class IdempotencyKey
{
    public static function deposit(int $shopId, string $checkoutId): string
    {
        return "deposit:{$shopId}:{$checkoutId}";
    }

    public static function installment(int $shopId, int $planId, int $sequence): string
    {
        return "installment:{$shopId}:{$planId}:{$sequence}";
    }

    public static function recurring(int $shopId, int $planId, string $billingCycleDate): string
    {
        return "recurring:{$shopId}:{$planId}:{$billingCycleDate}";
    }

    public static function upsell(int $shopId, int $flowId, int $offerId, string $parentOrderId, string $customerId): string
    {
        return "upsell:{$shopId}:{$flowId}:{$offerId}:{$parentOrderId}:{$customerId}";
    }

    public static function retry(int $shopId, int $paymentEventId, int $attemptNumber): string
    {
        return "retry:{$shopId}:{$paymentEventId}:{$attemptNumber}";
    }

    /**
     * A ONE-TIME product bought from an offer inside the customer's own account
     * area, charged on the card their subscription already saved.
     *
     * $customerRef is the SOURCE subscription's identity (its shopify/external
     * customer reference, else its internal id) — never the visitor's session, so
     * the same shopper clicking from two devices collapses to one key.
     *
     * $ymd is what makes this repeatable: a shopper may legitimately buy the same
     * add-on again next month, and a key without a date would make the second
     * purchase look like a duplicate of the first and silently take no money. A
     * DAY is the right grain — it is long enough that every double-click, retry
     * and refresh inside one shopping session collapses to one charge, and short
     * enough that nobody is refused a second deliberate purchase for long.
     */
    public static function accountOfferPurchase(
        int $shopId,
        int $offerId,
        int $targetId,
        string $customerRef,
        string $ymd,
    ): string {
        return "account_offer:{$shopId}:{$offerId}:{$targetId}:{$customerRef}:{$ymd}";
    }

    /**
     * A plain storefront checkout paid on the PayPlus page (the WooCommerce
     * gateway). PayPlus already charged; this key only keeps the RECORD single
     * under the push + pull double-confirmation.
     */
    /**
     * Money going BACK. Keyed by the ledger row plus how much had already
     * been returned before this attempt, so a repeat of the SAME refund
     * collapses at the gateway while a genuine second partial refund — a
     * different starting point — is its own request.
     */
    public static function refund(int $shopId, int $ledgerId, float $alreadyRefunded, float $amount): string
    {
        return sprintf('refund:%d:%d:%s:%s', $shopId, $ledgerId, number_format($alreadyRefunded, 2, '.', ''), number_format($amount, 2, '.', ''));
    }

    public static function gateway(int $shopId, string $orderId): string
    {
        return "gateway:{$shopId}:{$orderId}";
    }

    /**
     * A FAILED gateway attempt. Deliberately NOT the success key: failed →
     * succeeded is an illegal ledger transition, so a shopper who retries the
     * same order and succeeds must land on a fresh row — one row per failed
     * attempt ($ref = transaction uid or a status hash), one for the success.
     */
    public static function gatewayFailure(int $shopId, string $orderId, string $ref): string
    {
        return "gateway:{$shopId}:{$orderId}:fail:{$ref}";
    }
}
