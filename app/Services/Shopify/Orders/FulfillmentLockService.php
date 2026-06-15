<?php

namespace App\Services\Shopify\Orders;

use App\Models\InstallmentPlan;
use App\Services\Shopify\ShopifyAdminApi;

/**
 * Locks / releases fulfillment on the installments PARENT order. Ported +
 * multi-tenant-refactored from the reference FulfillmentLockService — the
 * per-shop client is injected instead of read from config.
 *
 * RELEASE GATE (scar): fulfillment is released ONLY at completion, when the plan
 * is fully paid (remaining_balance <= REMAINING_EPSILON). The strategy enforces
 * that gate; this service only performs the release mechanics:
 *   1. flip the fulfillment_lock metafield to false + status paid,
 *   2. swap the hold tag for the paid/ready tags,
 *   3. orderMarkAsPaid(parentGid) (GraphQL),
 *   4. createFulfillment(fulfillmentOrderIds).
 */
final class FulfillmentLockService
{
    public function __construct(private readonly ShopifyAdminApi $client) {}

    /** Update the running metafields on the parent during the installment phase. */
    public function updateProgressMetafields(InstallmentPlan $plan): void
    {
        $orderId = (string) ($plan->shopify_order_id ?? '');
        if ($orderId === '') {
            return;
        }

        $ns = (string) config('shopify.metafield_namespace');
        $mf = (array) config('shopify.metafields');

        $this->client->upsertOrderMetafield($orderId, $ns, (string) $mf['paid_amount'], number_format((float) $plan->total_charged, 2, '.', ''));
        $this->client->upsertOrderMetafield($orderId, $ns, (string) $mf['remaining_balance'], number_format($plan->remainingAmount(), 2, '.', ''));
        if ($plan->next_charge_at !== null) {
            $this->client->upsertOrderMetafield($orderId, $ns, (string) $mf['next_charge_at'], (string) $plan->next_charge_at->toIso8601String());
        }
    }

    /** Release fulfillment at completion. Caller MUST have verified isFullyPaid(). */
    public function release(InstallmentPlan $plan): void
    {
        $orderId = (string) ($plan->shopify_order_id ?? '');
        if ($orderId === '') {
            return;
        }

        $ns = (string) config('shopify.metafield_namespace');
        $mf = (array) config('shopify.metafields');
        $tags = (array) config('shopify.tags');

        $order = $this->client->fetchOrderWithMetafields($orderId);
        if ($order === []) {
            return;
        }

        // Swap hold/active tags for paid/ready.
        $current = collect(explode(',', (string) ($order['tags'] ?? '')))
            ->map(fn (string $t): string => trim($t))->filter()->all();
        $next = array_values(array_unique(array_filter(array_merge(
            array_diff($current, [$tags['installments_hold'] ?? '', $tags['installments_active'] ?? '']),
            [$tags['paid_release'] ?? '', $tags['ready_to_fulfill'] ?? ''],
        ))));
        $this->client->updateOrderTags($orderId, $next);

        // Flip the lock + status metafields.
        $this->client->upsertOrderMetafield($orderId, $ns, (string) $mf['fulfillment_lock'], 'false', 'boolean');
        $this->client->upsertOrderMetafield($orderId, $ns, (string) $mf['installment_status'], 'paid');
        $this->client->upsertOrderMetafield($orderId, $ns, (string) $mf['remaining_balance'], '0.00');

        // Mark paid (GraphQL) then create the fulfillment from the order's
        // fulfillment orders. Israeli PayPlus stores are not on Shopify Payments,
        // so markAsPaid here is a bookkeeping flip, not a real capture.
        if (! empty($plan->shopify_order_gid)) {
            $this->client->markOrderAsPaid((string) $plan->shopify_order_gid);
        }

        $fulfillmentOrderIds = array_values(array_filter(array_map(
            static fn (array $fo): ?int => isset($fo['id']) ? (int) $fo['id'] : null,
            $this->client->fetchOrderFulfillmentOrders($orderId),
        )));

        if ($fulfillmentOrderIds !== []) {
            $this->client->createFulfillment($fulfillmentOrderIds, notifyCustomer: true);
        }
    }
}
