<?php

namespace App\Listeners\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Domain\Lifecycle\OrderRefundService;
use App\Events\LedgerRowRefunded;
use App\Models\LoyaltyPointEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Take back the points a refunded purchase earned.
 *
 * Without this, buy → earn → redeem for credit → refund was a free-credit loop.
 * Two grants rode the refunded money and both are reversed, proportionally to
 * the refunded share:
 *   - the buyer's `earn_purchase` for this ledger row (keyForLedger);
 *   - the referrer's `referral` grant for the order the row belongs to
 *     (keyForReferral), when a referral code brought that order in.
 *
 * Every clawback is its own ledger event, keyed per refund slice so a replay
 * takes once, and floored at the member's balance (PointsEngine) — spent points
 * are not turned into a debt. Fail-soft: a loyalty bug must never surface as a
 * failed refund.
 */
final class ClawbackPointsOnRefund
{
    public function handle(LedgerRowRefunded $event): void
    {
        try {
            $this->clawback($event);
        } catch (\Throwable $e) {
            Log::warning('loyalty.clawback_on_refund_failed', [
                'shop_id' => $event->shopId,
                'ledger_id' => $event->row->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clawback(LedgerRowRefunded $event): void
    {
        if ($event->amount <= 0) {
            return;
        }

        $shop = Shop::query()->find($event->shopId);
        if (! $shop instanceof Shop) {
            return;
        }

        Tenant::run($shop, function () use ($event): void {
            $engine = app(PointsEngine::class);
            $row = $event->row;
            $meta = ['ledger_id' => (int) $row->getKey(), 'refund_ref' => $event->refundRef];

            // The refunded fraction of this row's money — the same share of
            // every grant that money earned.
            $share = (float) $row->amount > 0 ? min(1.0, $event->amount / (float) $row->amount) : 1.0;

            $earning = LoyaltyPointEvent::query()
                ->where('idempotency_key', LoyaltyPointEvent::keyForLedger((int) $row->getKey()))
                ->where('kind', LoyaltyPointEvent::KIND_EARN_PURCHASE)
                ->first();

            if ($earning !== null) {
                $engine->clawbackForRefund($earning, $share, $event->amount, $event->refundRef, $meta);
            }

            foreach ($this->orderIdsOf($row) as $orderId) {
                $referral = LoyaltyPointEvent::query()
                    ->where('idempotency_key', LoyaltyPointEvent::keyForReferral($orderId))
                    ->where('kind', LoyaltyPointEvent::KIND_REFERRAL)
                    ->first();

                if ($referral !== null) {
                    // The REFERRER never spent this money: points only, no spend.
                    $engine->clawbackForRefund($referral, $share, $event->amount, $event->refundRef,
                        $meta + ['order_id' => $orderId], reduceSpend: false);
                }
            }
        });
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
