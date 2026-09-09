<?php

namespace App\Domain\Refunds\Contracts;

use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\StoreRefundResult;
use App\Models\Shop;

/**
 * The STORE half of a refund — the platform seam, the same shape
 * `PlatformOrderStrategy` has for orders.
 *
 * The money leaving PayPlus is invisible inside WooCommerce and Shopify: the
 * merchant's own order still reads "paid", the stock is still gone, and the
 * customer's order page still shows a completed purchase. This is what tells the
 * store otherwise.
 *
 * EVERY IMPLEMENTATION MUST BE IDEMPOTENT. It runs after money has already
 * moved, which means it will be retried — by the merchant's own "retry store
 * sync" button, by a redelivered job, by a second attempt on a request that
 * timed out. `alreadyApplied()` is how it recognises its own work: a marker
 * written on the ORDER (a Shopify metafield, a WooCommerce order meta), because
 * that is the one record that survives everything on our side.
 */
interface StoreRefunder
{
    /**
     * Can this shop be told anything at all? A store whose credentials were
     * revoked (an uninstalled app, rotated WooCommerce keys) has no leg to run,
     * and asking anyway would turn every refund into a `needs_attention` task the
     * merchant cannot clear.
     */
    public function supports(Shop $shop): bool;

    /**
     * Record the refund on the order and restock what the merchant chose.
     * The order goes on standing — this is money back, not a cancellation.
     */
    public function refund(Shop $shop, RefundRequest $request): StoreRefundResult;

    /**
     * Cancel the order outright (after recording the refund on it) and restock.
     */
    public function cancel(Shop $shop, RefundRequest $request): StoreRefundResult;

    /**
     * Has this exact request already been applied to this store's order?
     * Read from the ORDER, never from our own tables — the point is to survive a
     * crash between the store call and our write.
     */
    public function alreadyApplied(Shop $shop, RefundRequest $request): bool;
}
