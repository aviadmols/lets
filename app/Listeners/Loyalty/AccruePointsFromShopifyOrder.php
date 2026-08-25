<?php

namespace App\Listeners\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Domain\Loyalty\Referral\ReferralService;
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

        if ($amount <= 0) {
            return;
        }

        $shop = Shop::query()->find($shopId);
        if (! $shop instanceof Shop) {
            return;
        }

        Tenant::run($shop, function () use ($shop, $customerRef, $amount, $orderId, $order): void {
            // A guest checkout still carries the order-level email even when
            // there is no customer record.
            $email = data_get($order, 'customer.email') ?? data_get($order, 'email');
            $email = is_string($email) && trim($email) !== '' ? trim($email) : null;

            // The referral is judged BEFORE the buyer's identity wall: a guest
            // has no member account to credit, but the friend who sent them
            // still earned their share — the order's email is enough to block
            // a self-referral.
            app(ReferralService::class)->attribute(
                shop: $shop,
                codes: $this->discountCodes($order),
                externalOrderId: $orderId,
                amount: $amount,
                buyerRef: $customerRef !== '' ? $customerRef : null,
                buyerEmail: $email,
            );

            if ($customerRef === '') {
                return; // a guest checkout has no identity to credit
            }

            app(PointsEngine::class)->accrue(
                customerRef: $customerRef,
                amount: $amount,
                idempotencyKey: LoyaltyPointEvent::keyForShopifyOrder($orderId),
                meta: [
                    'email' => $email,
                    'name' => $this->customerName($order),
                    'context' => 'shopify_order',
                    'order_id' => $orderId,
                ],
            );
        });
    }

    /**
     * The shopper's name as the order names them — kept on the accrual event so
     * a member who joined through the proxy page (which knows only a numeric
     * id) can have their loyalty account enriched later.
     *
     * @param  array<string, mixed>  $order
     */
    private function customerName(array $order): ?string
    {
        $name = trim(implode(' ', array_filter([
            trim((string) (data_get($order, 'customer.first_name') ?? '')),
            trim((string) (data_get($order, 'customer.last_name') ?? '')),
        ])));

        return $name !== '' ? $name : null;
    }

    /**
     * The discount codes Shopify reports on the order — where a referral code
     * arrives, because the shared link IS the platform's apply-discount URL.
     *
     * @param  array<string, mixed>  $order
     * @return list<string>
     */
    private function discountCodes(array $order): array
    {
        $codes = [];

        foreach ((array) ($order['discount_codes'] ?? []) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
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
