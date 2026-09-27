<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Support\Tenant;

/**
 * The two facts every WooCommerce upsell accept is now checked against:
 *   - LETS recorded the parent order as PAID for this customer (the `gateway` ledger
 *     row WooGatewayFinalizer writes) — WooParentOrderVerifier;
 *   - the offer was SHOWN on that order (the impression the offer endpoint records)
 *     — UpsellOfferEligibility.
 */
trait SeedsWooUpsellPurchase
{
    // === CONSTANTS ===
    private const SEEDED_PARENT_ORDER_TOTAL = 120.0;

    /** LETS confirmed $orderId paid by $customerRef (what WooGatewayFinalizer records). */
    protected function paidParentOrder(Shop $shop, string $orderId, string $customerRef): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $orderId, $customerRef): PaymentLedger {
            $row = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_GATEWAY,
                idempotencyKey: IdempotencyKey::gateway((int) $shop->getKey(), $orderId),
                amount: self::SEEDED_PARENT_ORDER_TOTAL,
                attributes: [
                    'shopify_order_id' => $orderId,
                    'shopify_customer_id' => $customerRef,
                ],
            );

            return Ledger::transition($row, LedgerStatus::SUCCEEDED);
        });
    }

    /** The offer endpoint showed $offer on $orderId. */
    protected function shownOn(Shop $shop, UpsellFlowOffer $offer, string $orderId): void
    {
        Tenant::run($shop, fn () => UpsellOfferEvent::record([
            'flow_id' => $offer->flow_id,
            'offer_id' => $offer->getKey(),
            'event_type' => OfferEventType::IMPRESSION,
            'parent_order_id' => $orderId,
            'currency' => 'ILS',
        ]));
    }

    /** Both: a paid parent order, with the offer shown on it. */
    protected function purchasedAndShown(Shop $shop, UpsellFlowOffer $offer, string $orderId, string $customerRef): void
    {
        $this->paidParentOrder($shop, $orderId, $customerRef);
        $this->shownOn($shop, $offer, $orderId);
    }
}
