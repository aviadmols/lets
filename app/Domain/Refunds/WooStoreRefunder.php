<?php

namespace App\Domain\Refunds;

use App\Domain\Refunds\Contracts\StoreRefunder;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell WooCommerce that money went back.
 *
 * WooCommerce already knows how to do the three things a merchant expects from a
 * refund — write the refund record on the order, put the stock back, and flip the
 * order to `refunded` once the refunds add up to the total. So this does not
 * re-implement any of them; it calls `POST /orders/{id}/refunds` and lets
 * WooCommerce be right about its own order.
 *
 * THE ONE FLAG THAT MATTERS is `api_refund`. It decides whose money moves.
 * FALSE means "record it, we already moved it", which is the truth for every
 * order this app charged on the PayPlus page — PayPlus has already sent the money
 * back by the time this runs. TRUE asks WooCommerce to ask the order's own
 * gateway, which is right for a PayPal order (R4) and catastrophic for a PayPlus
 * one: the shopper would be refunded twice and nothing in either system would say
 * so. The rail on the request is what answers it, and the rail is decided from
 * the ledger, never guessed here.
 *
 * IDEMPOTENT THROUGH THE ORDER. The marker is a meta key on the WooCommerce order
 * naming this request, written in the same call that creates the refund. A retry
 * — the merchant's button, a redelivered job — reads the order back and stops.
 * Our own tables are not the marker on purpose: the whole point is to survive a
 * crash between the store call and our write.
 */
final class WooStoreRefunder implements StoreRefunder
{
    // === CONSTANTS ===
    /** Order meta naming the request that was applied. `_` = hidden from the customer. */
    public const MARKER_PREFIX = '_lets_refund_request_';

    /** WooCommerce's own vocabulary. */
    private const STATUS_CANCELLED = 'cancelled';

    /** The note the merchant reads in the WC "Order notes" panel. */
    private const NOTE_KEY = 'refunds.store.woo_note';

    public function supports(Shop $shop): bool
    {
        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            && $shop->hasWooConnection()
            && $shop->isLive();
    }

    /**
     * What WooCommerce could still give back on this order: its total, minus the
     * refunds it has already recorded.
     *
     * Asked only for orders this app never charged. WooCommerce stores refunds as
     * NEGATIVE amounts on its refund objects, so the sum is added back rather
     * than subtracted — reading the sign the other way would offer a merchant a
     * ceiling twice the order's value.
     */
    public function refundableTotal(Shop $shop, string $orderId): ?float
    {
        $orderId = trim($orderId);
        if ($orderId === '' || ! $this->supports($shop)) {
            return null;
        }

        $client = WooClientFactory::for($shop);

        $order = $client->fetchOrder($orderId);
        if ($order === null) {
            return null;
        }

        $total = round((float) ($order['total'] ?? 0), 2);
        if ($total <= 0) {
            return null;
        }

        $refunded = 0.0;
        foreach ($client->fetchRefunds($orderId) as $refund) {
            $refunded = round($refunded + abs((float) (is_array($refund) ? ($refund['amount'] ?? 0) : 0)), 2);
        }

        return max(0.0, round($total - $refunded, 2));
    }

    public function refund(Shop $shop, RefundRequest $request): StoreRefundResult
    {
        return $this->apply($shop, $request, cancel: false);
    }

    /**
     * Cancel, in WooCommerce's order: the refund record FIRST, then the status.
     *
     * The other way round loses money. A cancelled order still needs its refund
     * row for the merchant's own reporting, and WooCommerce restocks on the
     * transition to `cancelled` — so writing the refund afterwards, with
     * `restock_items` still set, would put the same stock back twice.
     */
    public function cancel(Shop $shop, RefundRequest $request): StoreRefundResult
    {
        return $this->apply($shop, $request, cancel: true);
    }

    public function alreadyApplied(Shop $shop, RefundRequest $request): bool
    {
        $orderId = $this->orderId($request);
        if ($orderId === null) {
            return false;
        }

        try {
            $order = WooClientFactory::for($shop)->fetchOrder($orderId);
        } catch (Throwable $e) {
            // Unknown is not "already done". Answering true here would silently
            // skip the store leg of a refund that has moved real money.
            Log::warning('refunds.woo.marker_read_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $order !== null && $this->markerOn($order, $request);
    }

    // === Internals ===

    private function apply(Shop $shop, RefundRequest $request, bool $cancel): StoreRefundResult
    {
        $orderId = $this->orderId($request);
        if ($orderId === null) {
            return StoreRefundResult::skipped('no_order');
        }

        $client = WooClientFactory::for($shop);
        $amount = $request->refundedTotal();

        // Money that never moved gets no refund record — a ₪0 refund row on an
        // order is noise the merchant then has to explain to their accountant.
        // The cancellation still goes through: an unpaid order can be cancelled.
        $refund = null;
        if ($amount > 0) {
            $refund = $client->createRefund($orderId, array_filter([
                'amount' => number_format($amount, 2, '.', ''),
                'reason' => $this->reason($request),
                // WHOSE MONEY. False for everything this app charged: PayPlus has
                // already sent it back and this call only records that. True
                // ONLY on the delegated rail, where WooCommerce is being asked to
                // move the money through the order's own gateway because we
                // never charged it and have nothing of our own to reverse.
                'api_refund' => $request->isDelegated(),
                'restock_items' => (bool) $request->restock,
            ], static fn ($v): bool => $v !== null));
        }

        // The marker, on the ORDER, so a retry can recognise this exact request.
        // Written after the refund and before the cancel: if the process dies
        // between them, the retry finds the marker, skips the (already made)
        // refund and is left with the cancel — which is idempotent in WooCommerce.
        $client->updateOrder($orderId, [
            'meta_data' => [[
                'key' => self::MARKER_PREFIX.$request->getKey(),
                'value' => (string) now()->toIso8601String(),
            ]],
        ]);

        if ($cancel) {
            $client->updateOrder($orderId, ['status' => self::STATUS_CANCELLED]);

            Timeline::record(
                kind: Timeline::KIND_ORDER_CANCELLED_BY_MERCHANT,
                details: ['order_id' => $orderId, 'request_id' => (int) $request->getKey()],
                planId: $request->plan_id !== null ? (int) $request->plan_id : null,
                shopId: (int) $shop->getKey(),
            );
        }

        // A private note, because the merchant reading their own order deserves
        // to know this came from LETS and why.
        $this->note($shop, $orderId, $request, $amount);

        if ($request->restock && $amount > 0) {
            Timeline::record(
                kind: Timeline::KIND_RESTOCKED,
                details: ['order_id' => $orderId, 'request_id' => (int) $request->getKey()],
                planId: $request->plan_id !== null ? (int) $request->plan_id : null,
                shopId: (int) $shop->getKey(),
            );
        }

        return StoreRefundResult::done(
            reference: $refund !== null ? (string) ($refund['id'] ?? '') : null,
            details: array_filter([
                'cancelled' => $cancel ?: null,
                'restocked' => $request->restock ?: null,
                'api_refund' => $request->isDelegated() ?: null,
            ], static fn ($v): bool => $v !== null),
        );
    }

    /**
     * The order note. Best-effort: a note that will not post must never turn a
     * refund WooCommerce has already recorded into a `needs_attention` task.
     */
    private function note(Shop $shop, string $orderId, RefundRequest $request, float $amount): void
    {
        try {
            WooClientFactory::for($shop)->addOrderNote($orderId, (string) __(self::NOTE_KEY, [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => (string) $request->currency,
                'reason' => $this->reason($request) ?: (string) __('common.none'),
            ]));
        } catch (Throwable $e) {
            Log::info('refunds.woo.note_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $order */
    private function markerOn(array $order, RefundRequest $request): bool
    {
        $wanted = self::MARKER_PREFIX.$request->getKey();

        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            if ((string) (is_array($meta) ? ($meta['key'] ?? '') : '') === $wanted) {
                return true;
            }
        }

        return false;
    }

    private function orderId(RefundRequest $request): ?string
    {
        $orderId = trim((string) ($request->external_order_id ?? ''));

        return $orderId !== '' ? $orderId : null;
    }

    private function reason(RefundRequest $request): ?string
    {
        $reason = trim((string) ($request->reason ?? ''));

        return $reason !== '' ? $reason : null;
    }
}
