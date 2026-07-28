<?php

namespace App\Domain\Lifecycle;

use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Refund EVERY charge that belongs to one store order.
 *
 * A single order is often more than one charge: the checkout itself, plus an
 * accepted post-purchase upsell that was charged separately on the saved card.
 * Refunding "the order" from the payment row only ever reversed the row the
 * merchant happened to click, leaving the upsell charged — money the shopper was
 * still out, and paperwork that no longer described reality.
 *
 * Each charge is refunded through RefundService, so each keeps its own guarded
 * ledger transition AND produces its own credit note. Two charges therefore
 * produce two credit documents, which is what the merchant's books require: a
 * credit note credits ONE sale document, and these were two separate sales.
 *
 * Partial failure is reported, never hidden: refunding three charges where the
 * second is declined must not read as success, and the two that DID reverse must
 * not be rolled back — that money is already on its way to the shopper.
 */
final class OrderRefundService
{
    // === CONSTANTS ===
    /** Refundable charges are matched on either order column (see chargesFor). */
    private const ORDER_COLUMNS = ['shopify_order_id', 'parent_order_id'];

    public function __construct(private readonly RefundService $refunds) {}

    /**
     * @return array{ok: bool, refunded: int, failed: int, total: float, messages: list<string>}
     */
    public function refundOrder(Shop $shop, string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['ok' => false, 'refunded' => 0, 'failed' => 0, 'total' => 0.0, 'messages' => ['no_order']];
        }

        $charges = $this->chargesFor($shop, $orderId);

        if ($charges->isEmpty()) {
            return ['ok' => false, 'refunded' => 0, 'failed' => 0, 'total' => 0.0, 'messages' => ['nothing_to_refund']];
        }

        $refunded = 0;
        $failed = 0;
        $total = 0.0;
        $messages = [];

        // The tenant is bound around the WHOLE loop, not just the lookup:
        // RefundService re-reads each row under a lock through the tenant-scoped
        // model, and that scope fails closed — unbound, the re-read finds nothing
        // and every refund dies on a "model not found" instead of running.
        Tenant::run($shop, function () use ($shop, $orderId, $charges, &$refunded, &$failed, &$total, &$messages): void {
            foreach ($charges as $charge) {
                $result = $this->refunds->refund($charge);

                if ($result['ok'] ?? false) {
                    $refunded++;
                    $total = round($total + (float) $charge->amount, 2);

                    continue;
                }

                $failed++;
                $messages[] = (string) ($result['message'] ?? 'refund_failed');

                // Keep going. The remaining charges are independent money
                // movements, and stopping here would leave the shopper partly
                // refunded with no attempt made on the rest.
                Log::warning('refund.order.charge_failed', [
                    'shop_id' => $shop->getKey(),
                    'order_id' => $orderId,
                    'ledger_id' => $charge->getKey(),
                    'message' => $result['message'] ?? null,
                ]);
            }
        });

        return [
            'ok' => $failed === 0,
            'refunded' => $refunded,
            'failed' => $failed,
            'total' => $total,
            'messages' => array_values(array_unique($messages)),
        ];
    }

    /**
     * Every SUCCEEDED charge on this order, oldest first.
     *
     * Matched on both order columns because an upsell records the purchase it
     * followed as `parent_order_id` and has no order of its own — it is part of
     * the same order to everyone except the database.
     *
     * @return \Illuminate\Support\Collection<int, PaymentLedger>
     */
    public function chargesFor(Shop $shop, string $orderId): \Illuminate\Support\Collection
    {
        return Tenant::run($shop, static fn () => PaymentLedger::query()
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->where(function (Builder $q) use ($orderId): void {
                foreach (self::ORDER_COLUMNS as $column) {
                    $q->orWhere($column, $orderId);
                }
            })
            ->orderBy('id')
            ->get());
    }
}
