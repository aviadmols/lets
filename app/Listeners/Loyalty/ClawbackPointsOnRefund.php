<?php

namespace App\Listeners\Loyalty;

use App\Domain\Loyalty\RefundClawback;
use App\Events\LedgerRowRefunded;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Take back the points a refunded ledger charge earned.
 *
 * Without this, buy → earn → redeem for credit → refund was a free-credit loop.
 * The work lives in RefundClawback (shared with the Shopify refunds/create
 * webhook, which covers plain Shopify orders that never touched our ledger):
 * the buyer's `earn_purchase` for the row and the referrer's `referral` grant
 * for its order, both by the refunded share, keyed per refund slice, floored at
 * the balance. Fail-soft: a loyalty bug must never surface as a failed refund.
 */
final class ClawbackPointsOnRefund
{
    public function handle(LedgerRowRefunded $event): void
    {
        try {
            if ($event->amount <= 0) {
                return;
            }

            $shop = Shop::query()->find($event->shopId);
            if (! $shop instanceof Shop) {
                return;
            }

            Tenant::run($shop, fn () => app(RefundClawback::class)
                ->forLedgerRow($event->row, $event->amount, $event->refundRef));
        } catch (\Throwable $e) {
            Log::warning('loyalty.clawback_on_refund_failed', [
                'shop_id' => $event->shopId,
                'ledger_id' => $event->row->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
