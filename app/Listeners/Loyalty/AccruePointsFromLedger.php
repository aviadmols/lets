<?php

namespace App\Listeners\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Events\LedgerRowSucceeded;
use App\Models\LoyaltyPointEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Turn recorded money into points.
 *
 * Rides `LedgerRowSucceeded`, so it covers every PayPlus-side success at once:
 * plain WooCommerce gateway orders, deposits, installments, recurring cycles and
 * upsells. The ledger row id is the idempotency key, so a retried job or a
 * re-delivered callback that lands on the SAME row can never mint twice.
 *
 * Fail-soft by construction: points are a courtesy, money is not. Anything that
 * goes wrong here is logged and swallowed — a loyalty bug must never surface as
 * a failed charge.
 */
final class AccruePointsFromLedger
{
    public function handle(LedgerRowSucceeded $event): void
    {
        try {
            $this->accrue($event->shopId, $event->row);
        } catch (\Throwable $e) {
            Log::warning('loyalty.accrual_from_ledger_failed', [
                'shop_id' => $event->shopId,
                'ledger_id' => $event->row->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function accrue(int $shopId, PaymentLedger $row): void
    {
        $customerRef = trim((string) ($row->shopify_customer_id ?? ''));
        $amount = round((float) $row->amount, 2);

        if ($customerRef === '' || $amount <= 0) {
            return; // nothing to attribute the points to
        }

        $shop = Shop::query()->find($shopId);
        if (! $shop instanceof Shop) {
            return;
        }

        // Bind the tenant explicitly: this may run on a worker that just handled
        // another shop, and every loyalty query is tenant-scoped.
        Tenant::run($shop, function () use ($row, $customerRef, $amount): void {
            app(PointsEngine::class)->accrue(
                customerRef: $customerRef,
                amount: $amount,
                idempotencyKey: LoyaltyPointEvent::keyForLedger((int) $row->getKey()),
                sourceLedgerId: (int) $row->getKey(),
                meta: [
                    'email' => $row->customer_email,
                    'context' => (string) $row->charge_context,
                ],
            );
        });
    }
}
