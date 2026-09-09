<?php

namespace App\Domain\Refunds;

use App\Domain\Refunds\Contracts\StoreRefunder;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell Shopify that money went back.
 *
 * THE TRANSACTION IS THE WHOLE PROBLEM. Shopify's `refundCreate` takes a list of
 * transactions, and each one either MOVES MONEY or merely records it, depending
 * on the gateway named. An order this app charged was marked paid through
 * Shopify's MANUAL gateway (ShopifyOrderCreator / markOrderAsPaid) — the real
 * money went through PayPlus, and PayPlus has already sent it back by the time
 * this runs. So the refund transaction here is a manual one: it makes the order
 * read `refunded` / `partially_refunded` without asking Shopify to move anything.
 *
 * Passing the real parent transaction instead would be right for a Shopify
 * Payments order (R4) and catastrophic here: the shopper would be refunded twice
 * and neither system would say so. The rail on the request answers it, and the
 * rail is decided from the ledger, never guessed in this class.
 *
 * RESTOCKING NEEDS A LOCATION. Shopify's `refundLineItems` cannot return stock
 * without one, so it is read from the order's own fulfillment orders and falls
 * back to the shop's first location. If neither answers, the refund still goes
 * through WITHOUT the restock rather than failing — money back with stock not
 * returned is a shelf count to fix; a failed store leg over a stock count is a
 * merchant chasing a refund that already happened.
 *
 * IDEMPOTENT THROUGH THE ORDER. The marker is an order metafield naming this
 * request. Our own tables are deliberately not the marker: the point is to
 * survive a crash between the Shopify call and our write.
 */
final class ShopifyStoreRefunder implements StoreRefunder
{
    // === CONSTANTS ===
    /** Metafield key naming the request that was applied (namespace from config). */
    public const MARKER_KEY_PREFIX = 'refund_request_';

    /**
     * The gateway a RECORD-ONLY refund transaction is filed under. It must match
     * the gateway the sale was recorded with — Shopify refuses a refund
     * transaction whose gateway does not correspond to a transaction on the order.
     */
    private const CONFIG_TX_GATEWAY = 'shopify.order_tx_gateway';

    /** Shopify's restock vocabulary. RETURN puts it back on the shelf. */
    private const RESTOCK_RETURN = 'RETURN';

    private const RESTOCK_NONE = 'NO_RESTOCK';

    /** How Shopify refuses a field behind PROTECTED CUSTOMER DATA. */
    private const PROTECTED_MARKER = 'not approved to use the';

    public function supports(Shop $shop): bool
    {
        return $shop->platform === Shop::PLATFORM_SHOPIFY
            && $shop->hasShopifyConnection()
            && $shop->isLive();
    }

    public function refund(Shop $shop, RefundRequest $request): StoreRefundResult
    {
        $orderId = $this->orderId($request);
        if ($orderId === null) {
            return StoreRefundResult::skipped('no_order');
        }

        try {
            $reference = $this->createRefund($shop, $request, $orderId);
            $this->mark($shop, $request, $orderId);

            return StoreRefundResult::done($reference, array_filter([
                'restocked' => $request->restock ?: null,
            ], static fn ($v): bool => $v !== null));
        } catch (Throwable $e) {
            return StoreRefundResult::failed($this->reason($e), ['message' => $e->getMessage()]);
        }
    }

    /**
     * Cancel, in Shopify's order: the refund record FIRST, then `orderCancel`.
     *
     * `orderCancel` can refund and restock by itself, but only through the
     * order's own gateway — which for a PayPlus-charged order would be a second
     * refund of money PayPlus already returned. So the money side is recorded
     * first, as a manual transaction, and the cancel is then asked to move
     * nothing.
     */
    public function cancel(Shop $shop, RefundRequest $request): StoreRefundResult
    {
        $orderId = $this->orderId($request);
        if ($orderId === null) {
            return StoreRefundResult::skipped('no_order');
        }

        try {
            $reference = $request->refundedTotal() > 0
                ? $this->createRefund($shop, $request, $orderId)
                : null;

            $this->cancelOrder($shop, $request, $orderId);
            $this->mark($shop, $request, $orderId);

            Timeline::record(
                kind: Timeline::KIND_ORDER_CANCELLED_BY_MERCHANT,
                details: ['order_id' => $orderId, 'request_id' => (int) $request->getKey()],
                planId: $request->plan_id !== null ? (int) $request->plan_id : null,
                shopId: (int) $shop->getKey(),
            );

            return StoreRefundResult::done($reference, ['cancelled' => true]);
        } catch (Throwable $e) {
            return StoreRefundResult::failed($this->reason($e), ['message' => $e->getMessage()]);
        }
    }

    public function alreadyApplied(Shop $shop, RefundRequest $request): bool
    {
        $orderId = $this->orderId($request);
        if ($orderId === null) {
            return false;
        }

        try {
            $order = ShopifyClientFactory::for($shop)->fetchOrderWithMetafields($orderId);
        } catch (Throwable $e) {
            // Unknown is not "already done": answering true here would skip the
            // store leg of a refund that has moved real money.
            Log::warning('refunds.shopify.marker_read_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $key = $this->markerKey($request);

        foreach ((array) ($order['metafields'] ?? []) as $metafield) {
            if (is_array($metafield) && (string) ($metafield['key'] ?? '') === $key) {
                return true;
            }
        }

        return false;
    }

    // === Internals ===

    /** @return string|null the Shopify refund id, when it named one */
    private function createRefund(Shop $shop, RefundRequest $request, string $orderId): ?string
    {
        $amount = $request->refundedTotal();
        if ($amount <= 0) {
            return null;
        }

        $client = ShopifyClientFactory::for($shop);
        $orderGid = $this->gid($orderId);

        $input = array_filter([
            'orderId' => $orderGid,
            'note' => $this->reasonText($request),
            'notify' => (bool) $request->notify,
            'refundLineItems' => $this->refundLineItems($shop, $request, $orderId),
            'transactions' => [[
                'orderId' => $orderGid,
                // Manual: PayPlus already moved this money. See the class doc.
                'gateway' => (string) config(self::CONFIG_TX_GATEWAY, 'manual'),
                'kind' => 'REFUND',
                'amount' => number_format($amount, 2, '.', ''),
            ]],
        ], static fn ($v): bool => $v !== null && $v !== []);

        $body = $client->graphql(<<<'GQL'
        mutation letsRefundCreate($input: RefundInput!) {
          refundCreate(input: $input) {
            refund { id }
            userErrors { field message }
          }
        }
        GQL, ['input' => $input]);

        $this->assertNoUserErrors($body, 'data.refundCreate.userErrors');

        return (string) (data_get($body, 'data.refundCreate.refund.id') ?? '') ?: null;
    }

    /**
     * Cancel the order, asking Shopify to move NO money and to leave the stock
     * decision to the refund that was just recorded.
     */
    private function cancelOrder(Shop $shop, RefundRequest $request, string $orderId): void
    {
        $body = ShopifyClientFactory::for($shop)->graphql(<<<'GQL'
        mutation letsOrderCancel($id: ID!, $reason: OrderCancelReason!, $refund: Boolean!, $restock: Boolean!, $notify: Boolean) {
          orderCancel(orderId: $id, reason: $reason, refund: $refund, restock: $restock, notifyCustomer: $notify) {
            userErrors { field message }
          }
        }
        GQL, [
            'id' => $this->gid($orderId),
            'reason' => 'OTHER',
            // FALSE, always. The money side is already recorded (or there was
            // none); letting Shopify refund here is the double-refund.
            'refund' => false,
            // The refund above already restocked when the merchant asked for it;
            // asking twice is how the same stock comes back twice.
            'restock' => $request->refundedTotal() > 0 ? false : (bool) $request->restock,
            'notify' => (bool) $request->notify,
        ]);

        $this->assertNoUserErrors($body, 'data.orderCancel.userErrors');
    }

    /**
     * The lines to return to stock, or null when nothing should be restocked.
     *
     * Shopify cannot restock without a location, so a shop whose location cannot
     * be read gets a refund WITHOUT the restock rather than no refund at all.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function refundLineItems(Shop $shop, RefundRequest $request, string $orderId): ?array
    {
        if (! $request->restock) {
            return null;
        }

        $lines = (array) ($request->lines ?? []);
        if ($lines === []) {
            // No basket was chosen — an amount-based refund. Shopify has no
            // "restock proportionally" and inventing quantities would put back
            // stock the merchant never said to return.
            return null;
        }

        $locationGid = $this->locationFor($shop, $orderId);
        if ($locationGid === null) {
            Log::info('refunds.shopify.restock_skipped_no_location', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
            ]);

            return null;
        }

        $out = [];
        foreach ($lines as $line) {
            $lineId = trim((string) (is_array($line) ? ($line['line_id'] ?? '') : ''));
            $quantity = (int) (is_array($line) ? ($line['quantity'] ?? 0) : 0);

            if ($lineId === '' || $quantity <= 0) {
                continue;
            }

            $out[] = [
                'lineItemId' => str_starts_with($lineId, 'gid://') ? $lineId : 'gid://shopify/LineItem/'.$lineId,
                'quantity' => $quantity,
                'restockType' => self::RESTOCK_RETURN,
                'locationId' => $locationGid,
            ];
        }

        return $out !== [] ? $out : null;
    }

    /**
     * Where the stock goes back to: the order's own fulfillment location, else
     * the shop's first location. Null when neither can be read.
     */
    private function locationFor(Shop $shop, string $orderId): ?string
    {
        $client = ShopifyClientFactory::for($shop);

        try {
            foreach ($client->fetchOrderFulfillmentOrders($orderId) as $fulfillmentOrder) {
                $locationId = (string) (is_array($fulfillmentOrder)
                    ? ($fulfillmentOrder['assigned_location_id'] ?? '')
                    : '');

                if ($locationId !== '' && $locationId !== '0') {
                    return 'gid://shopify/Location/'.$locationId;
                }
            }
        } catch (Throwable) {
            // Fall through to the shop's default location.
        }

        try {
            $body = $client->graphql('query letsPrimaryLocation { locations(first: 1) { nodes { id } } }');

            $gid = (string) (data_get($body, 'data.locations.nodes.0.id') ?? '');

            return $gid !== '' ? $gid : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The order metafield that says "this request has been applied here". */
    private function mark(Shop $shop, RefundRequest $request, string $orderId): void
    {
        ShopifyClientFactory::for($shop)->upsertOrderMetafield(
            $orderId,
            (string) config('shopify.metafield_namespace', 'lets'),
            $this->markerKey($request),
            (string) now()->toIso8601String(),
        );
    }

    private function markerKey(RefundRequest $request): string
    {
        return self::MARKER_KEY_PREFIX.$request->getKey();
    }

    /** @param array<string, mixed> $body */
    private function assertNoUserErrors(array $body, string $path): void
    {
        $errors = (array) data_get($body, $path, []);

        if ($errors === []) {
            return;
        }

        $messages = array_map(
            static fn ($e): string => (string) (is_array($e) ? ($e['message'] ?? '') : ''),
            $errors,
        );

        throw new \RuntimeException(implode(' · ', array_filter($messages)) ?: 'shopify_user_error');
    }

    /**
     * A short, translatable code for the drawer. A scope the merchant has not
     * been approved for is named specifically, because that is a Partner
     * Dashboard action they can take rather than a fault they cannot.
     */
    private function reason(Throwable $e): string
    {
        return str_contains($e->getMessage(), self::PROTECTED_MARKER)
            ? 'protected_data_pending'
            : 'store_exception';
    }

    private function reasonText(RefundRequest $request): ?string
    {
        $reason = trim((string) ($request->reason ?? ''));

        return $reason !== '' ? $reason : null;
    }

    private function orderId(RefundRequest $request): ?string
    {
        $orderId = trim((string) ($request->external_order_id ?? ''));

        return $orderId !== '' ? $orderId : null;
    }

    private function gid(string $orderId): string
    {
        return str_starts_with($orderId, 'gid://') ? $orderId : 'gid://shopify/Order/'.$orderId;
    }
}
