<?php

namespace App\Listeners\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyPointEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Points for money that never touches OUR ledger.
 *
 * On the Shopify side two kinds of sale exist that `payment_ledger` never sees:
 * a plain checkout paid through Shopify Payments, and a subscription-contract
 * cycle Shopify billed itself. Both produce a real Shopify order, so the
 * `shopify.order.paid` webhook is the one place both become observable.
 *
 * Two walls against double counting:
 *  1. the idempotency key is the ORDER id, so the same order can only ever mint
 *     one event no matter how often the webhook is redelivered;
 *  2. orders we created ourselves for a PayPlus charge are SKIPPED — that money
 *     already earned its points through the ledger listener, and paying twice
 *     for one purchase is the failure a loyalty program never recovers from.
 */
final class AccruePointsFromShopifyOrder
{
    // === CONSTANTS ===
    /** Note attribute Shopify orders we create carry (ShopifyOrderCreator). */
    private const LETS_PLAN_ATTRIBUTE = 'pps_plan_public_id';

    /** @param array<int, array<string, mixed>> $payload the event's single argument bag */
    public function handle(array ...$payload): void
    {
        $data = (array) ($payload[0] ?? []);

        try {
            $this->accrue($data);
        } catch (\Throwable $e) {
            Log::warning('loyalty.accrual_from_shopify_order_failed', [
                'shop_id' => $data['shop_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function accrue(array $data): void
    {
        $shopId = (int) ($data['shop_id'] ?? 0);
        $orderId = trim((string) ($data['order_id'] ?? ''));
        $order = (array) ($data['payload'] ?? []);

        if ($shopId <= 0 || $orderId === '' || $order === []) {
            return;
        }

        if ($this->isOurOrder($order, $orderId, $shopId)) {
            return; // already earned through the ledger — never pay twice
        }

        $customerRef = trim((string) (data_get($order, 'customer.id') ?? ''));
        $amount = round((float) (data_get($order, 'total_price') ?? 0), 2);

        if ($customerRef === '' || $amount <= 0) {
            return; // a guest checkout has no identity to credit
        }

        $shop = Shop::query()->find($shopId);
        if (! $shop instanceof Shop) {
            return;
        }

        Tenant::run($shop, function () use ($customerRef, $amount, $orderId, $order): void {
            app(PointsEngine::class)->accrue(
                customerRef: $customerRef,
                amount: $amount,
                idempotencyKey: LoyaltyPointEvent::keyForShopifyOrder($orderId),
                meta: [
                    'email' => data_get($order, 'customer.email') ?? data_get($order, 'email'),
                    'context' => 'shopify_order',
                    'order_id' => $orderId,
                ],
            );
        });
    }

    /**
     * Did WE create this order for a charge that already produced a ledger row?
     * Two independent tells: the plan note-attribute our order creator stamps,
     * and a ledger row that names the order.
     *
     * @param array<string, mixed> $order
     */
    private function isOurOrder(array $order, string $orderId, int $shopId): bool
    {
        foreach ((array) ($order['note_attributes'] ?? []) as $attribute) {
            if ((string) ($attribute['name'] ?? '') === self::LETS_PLAN_ATTRIBUTE
                && trim((string) ($attribute['value'] ?? '')) !== '') {
                return true;
            }
        }

        return PaymentLedger::query()
            ->where('shop_id', $shopId)
            ->where('shopify_order_id', $orderId)
            ->exists();
    }
}
