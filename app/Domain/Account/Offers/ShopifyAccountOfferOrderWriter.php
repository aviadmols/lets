<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyDraftOrderService;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Shopify half of the account-offer order seam — the store record of a
 * one-time product bought inside the personal area on the subscription's saved
 * PayPlus token.
 *
 * Shape: draft order COMPLETED-as-paid (the upsell-child pattern) — the money
 * already moved, so the draft exists only to become a paid order. Linked to the
 * SUBSCRIPTION via pps_plan_public_id, which is also the double-accrual tell:
 * AccruePointsFromShopifyOrder skips any order carrying it, so this sale earns
 * loyalty points exactly once (through the ledger listener), never twice.
 *
 * MONEY LAW. Called ONLY AFTER the SUCCEEDED ledger row exists. A failure here
 * never unwinds the charge — log loudly, return null, the caller flags a
 * reconcile.
 */
final class ShopifyAccountOfferOrderWriter implements OfferOrderWriter
{
    // === CONSTANTS ===
    /** The note-attribute channel Shopify orders use for the role mark. */
    public const ATTR_ORDER_ROLE = 'pps_order_role';

    public function available(Shop $shop): bool
    {
        return $shop->platform === Shop::PLATFORM_SHOPIFY && $shop->hasShopifyConnection();
    }

    public function create(
        Shop $shop,
        InstallmentPlan $source,
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
    ): ?string {
        if (! $this->available($shop)) {
            // The purchase service refuses up-front through available(); this is
            // the last-line belt — never silent, because the money moved.
            Log::warning('account_offer.order.no_shopify_connection', [
                'shop_id' => $shop->getKey(),
                'offer_id' => $offer->getKey(),
                'target_id' => $target->getKey(),
                'amount' => $quote->amount,
            ]);

            return null;
        }

        try {
            $service = new ShopifyDraftOrderService(ShopifyClientFactory::for($shop));

            $result = $service->createAccountOfferOrder(
                plan: $source,
                customer: [
                    'id' => (string) ($source->shopify_customer_id ?? ''),
                    'email' => (string) ($source->customer_email ?? ''),
                    'currency' => $quote->currency,
                ],
                lineItem: array_filter([
                    'title' => $quote->itemTitle,
                    'price' => $quote->amount,
                    'quantity' => $quote->quantity,
                    // A real variant so stock decrements and the product shows.
                    // The service normalizes to the NUMERIC id REST takes; a
                    // non-numeric id yields a title-only line, never a wrong one.
                    'variant_gid' => trim((string) ($quote->variant?->external_variant_id ?? '')) ?: null,
                ], static fn ($v): bool => $v !== null),
                attributes: [
                    self::ATTR_ORDER_ROLE => self::ROLE_ACCOUNT_OFFER,
                    self::META_OFFER_ID => (string) $offer->getKey(),
                    self::META_TARGET_ID => (string) $target->getKey(),
                ],
            );

            $orderId = trim((string) ($result['shopify_order_id'] ?? ''));

            return $orderId !== '' ? $orderId : null;
        } catch (Throwable $e) {
            // Compensating action: the charge SUCCEEDED but the order did not.
            // Flag for manual reconcile — never lose it silently.
            Log::error('account_offer.order.create_failed', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $source->getKey(),
                'offer_id' => $offer->getKey(),
                'target_id' => $target->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
