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
}
