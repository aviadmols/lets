<?php

namespace App\Domain\Loyalty;

use App\Domain\Lifecycle\OrderRefundService;
use App\Models\LoyaltyPointEvent;
use App\Models\PaymentLedger;

/**
 * Take back the points refunded money earned — the ONE clawback path.
 *
 * Two rails earn points and both must give them back when the money leaves:
 *  - a charge in OUR ledger (earn keyed by the ledger row) — refunded through
 *    RefundService / the store-refund mirror → LedgerRowRefunded → forLedgerRow;
 *  - a plain Shopify order LETS never charged (earn keyed by the order id) —
 *    refunded in Shopify → the refunds/create webhook → forShopifyOrder.
 *
 * Both end at PointsEngine::clawbackForRefund: proportional to the refunded
 * share, keyed per refund (a replay takes once), and floored at the member's
 * balance (spent points never become a debt). The referrer's grant on the same
 * order is reversed by the same share, points only.
 *
 * Callers run with the Tenant bound; every read below is shop-scoped.
 */
final class RefundClawback
{
    public function __construct(private readonly PointsEngine $engine) {}

    /** Money refunded from one of OUR ledger rows. */
    public function forLedgerRow(PaymentLedger $row, float $refundedAmount, string $refundRef): void
    {
        if ($refundedAmount <= 0) {
            return;
        }

        $meta = ['ledger_id' => (int) $row->getKey(), 'refund_ref' => $refundRef];
        $share = (float) $row->amount > 0 ? min(1.0, $refundedAmount / (float) $row->amount) : 1.0;

        $earning = $this->find(LoyaltyPointEvent::keyForLedger((int) $row->getKey()), LoyaltyPointEvent::KIND_EARN_PURCHASE);
        if ($earning !== null) {
            $this->engine->clawbackForRefund($earning, $share, $refundedAmount, $refundRef, $meta);
        }

        foreach ($this->orderIdsOf($row) as $orderId) {
            $this->clawbackReferral($orderId, $share, $refundedAmount, $refundRef, $meta + ['order_id' => $orderId]);
        }
    }

    /**
     * Money refunded from a plain Shopify order (points earned through
     * AccruePointsFromShopifyOrder). The share is measured against the amount
     * the order earned on — never the refund's own claim about the order.
     */
    public function forShopifyOrder(string $orderId, float $refundedAmount, string $refundRef): void
    {
        $orderId = trim($orderId);
        if ($orderId === '' || $refundedAmount <= 0) {
            return;
        }

        $meta = ['order_id' => $orderId, 'refund_ref' => $refundRef, 'source' => 'shopify_refund'];

        $earning = $this->find(LoyaltyPointEvent::keyForShopifyOrder($orderId), LoyaltyPointEvent::KIND_EARN_PURCHASE);
        if ($earning !== null) {
            $this->engine->clawbackForRefund(
                $earning, $this->shareOf($refundedAmount, (float) $earning->amount), $refundedAmount, $refundRef, $meta,
            );
        }

        $referral = $this->find(LoyaltyPointEvent::keyForReferral($orderId), LoyaltyPointEvent::KIND_REFERRAL);
        if ($referral !== null) {
            $base = (float) ($earning?->amount ?? data_get($referral->meta, 'amount', 0));
            $this->engine->clawbackForRefund(
                $referral, $this->shareOf($refundedAmount, $base), $refundedAmount, $refundRef, $meta, reduceSpend: false,
            );
        }
    }

    private function clawbackReferral(string $orderId, float $share, float $amount, string $refundRef, array $meta): void
    {
        $referral = $this->find(LoyaltyPointEvent::keyForReferral($orderId), LoyaltyPointEvent::KIND_REFERRAL);

        if ($referral !== null) {
            // The REFERRER never spent this money: points only, no spend.
            $this->engine->clawbackForRefund($referral, $share, $amount, $refundRef, $meta, reduceSpend: false);
        }
    }

    /** Refunded fraction; an unknown base is treated as a full refund (the cap still holds). */
    private function shareOf(float $refunded, float $base): float
    {
        return $base > 0 ? min(1.0, $refunded / $base) : 1.0;
    }

    private function find(string $key, string $kind): ?LoyaltyPointEvent
    {
        return LoyaltyPointEvent::query()
            ->where('idempotency_key', $key)
            ->where('kind', $kind)
            ->first();
    }

    /** @return list<string> */
    private function orderIdsOf(PaymentLedger $row): array
    {
        $ids = [];
        foreach (OrderRefundService::ORDER_COLUMNS as $column) {
            $value = trim((string) ($row->getAttribute($column) ?? ''));
            if ($value !== '') {
                $ids[$value] = $value;
            }
        }

        return array_values($ids);
    }
}
