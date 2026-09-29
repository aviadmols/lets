<?php

namespace App\Services\Shopify\Webhooks;

use App\Domain\Loyalty\RefundClawback;
use App\Models\PaymentLedger;
use App\Models\WebhookEvent;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Handles refunds/create: a refund issued in Shopify takes back the loyalty
 * points its order earned.
 *
 * Scope is deliberately narrow — PLAIN Shopify orders, whose points came from
 * the orders/paid webhook (AccruePointsFromShopifyOrder). An order LETS created
 * for a PayPlus charge earned through our ledger instead, and its refund reaches
 * loyalty through LedgerRowRefunded; clawing it back here too would take the
 * same share twice, so such orders are skipped.
 *
 * Money moved is read from the refund's own SUCCESSFUL `refund` transactions —
 * a restock-only refund (no money back) takes nothing. The clawback is keyed on
 * the Shopify refund id, so a redelivered webhook (or a second registration of
 * the topic) takes once. Floored at the member's balance by the engine.
 *
 * Runs with the Tenant bound by ProcessShopifyWebhookJob, after
 * VerifyShopifyWebhook proved the HMAC.
 */
final class RefundCreatedHandler implements WebhookHandler
{
    // === CONSTANTS ===
    /** Prefix of the per-refund reference the clawback key is built from. */
    public const REFUND_REF_PREFIX = 'shopify-refund:';

    private const TRANSACTION_KIND_REFUND = 'refund';

    private const TRANSACTION_STATUS_SUCCESS = 'success';

    public function __construct(private readonly RefundClawback $clawback) {}

    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return;
        }

        $payload = (array) $event->raw_payload;
        $refundId = trim((string) ($payload['id'] ?? ''));
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $amount = $this->refundedAmount($payload);

        if ($refundId === '' || $orderId === '' || $amount <= 0) {
            return;
        }

        if ($this->isLedgerOrder($orderId)) {
            Log::info('shopify.refund_created.ledger_order_skipped', [
                'shop_id' => $shop->id, 'order_id' => $orderId, 'refund_id' => $refundId,
            ]);

            return;
        }

        try {
            $this->clawback->forShopifyOrder($orderId, $amount, self::REFUND_REF_PREFIX.$refundId);
        } catch (\Throwable $e) {
            // A loyalty bug must never fail the webhook into a retry storm.
            Log::warning('shopify.refund_created.clawback_failed', [
                'shop_id' => $shop->id,
                'order_id' => $orderId,
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Sum of the refund's successful money-back transactions. */
    private function refundedAmount(array $payload): float
    {
        $total = 0.0;

        foreach ((array) ($payload['transactions'] ?? []) as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            if (strtolower((string) ($transaction['kind'] ?? '')) !== self::TRANSACTION_KIND_REFUND
                || strtolower((string) ($transaction['status'] ?? '')) !== self::TRANSACTION_STATUS_SUCCESS) {
                continue;
            }

            $total += max(0.0, (float) ($transaction['amount'] ?? 0));
        }

        return round($total, 2);
    }

    /** Did this order's money run through our ledger (so its refund is clawed back there)? */
    private function isLedgerOrder(string $orderId): bool
    {
        return PaymentLedger::query()
            ->where(fn ($q) => $q->where('shopify_order_id', $orderId)->orWhere('parent_order_id', $orderId))
            ->exists();
    }
}
